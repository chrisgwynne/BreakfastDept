<?php

declare(strict_types=1);

namespace Breakfast\Platform\Payments;

use Breakfast\Platform\Support\Clock;
use Breakfast\Platform\Support\Platform;
use Breakfast\Platform\Support\Uuid;

/**
 * Orchestrates Stripe deposit/invoice payments end to end.
 *
 * Amounts are ALWAYS computed server-side from the invoice; the client never
 * supplies a payment amount. A Checkout Session is created with stable
 * invoice/payment references and an idempotency key. An invoice is only marked
 * paid/part-paid by reconciling a VERIFIED webhook (never a success redirect),
 * and reconciliation is idempotent, currency- and amount-checked, and audited;
 * anomalies raise an operations warning rather than being silently absorbed.
 */
final class PaymentsService
{
    public function __construct(
        private readonly Platform $platform,
        private readonly StripeSettings $settings,
    ) {
    }

    private function db(): \Breakfast\Platform\Support\Database
    {
        return $this->platform->db();
    }

    private function client(): StripeClient
    {
        $key = $this->settings->secretKey();
        if ($key === '') {
            throw new PaymentException(409, 'Stripe is not configured.');
        }

        return new StripeClient($key, $this->settings->baseUrl());
    }

    // ==================================================================
    // Checkout
    // ==================================================================

    /**
     * Create a Stripe Checkout Session for an invoice's outstanding balance.
     *
     * The site base URL is supplied by the caller (the platform service is kept
     * free of any hard Kirby dependency); configured success/cancel URLs still
     * take precedence over the derived hosted-invoice fallback.
     *
     * @return array<string,mixed> {url, payment_id}
     */
    public function createCheckout(string $invoiceUuid, string $actor, string $siteUrl = ''): array
    {
        if (!$this->settings->enabled()) {
            throw new PaymentException(409, 'Online payments are not enabled.');
        }
        $inv = $this->platform->invoices()->find($invoiceUuid);
        if ($inv === null) {
            throw new PaymentException(404, 'Invoice not found.');
        }
        if (in_array((string) $inv['status'], ['draft', 'void'], true)) {
            throw new PaymentException(409, 'This invoice can’t be paid online.');
        }
        // Server-computed outstanding amount, in minor units.
        $amountDue = (int) $inv['total'] - (int) $inv['amount_paid'];
        if ($amountDue <= 0) {
            throw new PaymentException(409, 'This invoice has nothing left to pay.');
        }
        $currency = strtoupper((string) ($inv['currency'] ?? $this->settings->currency()));

        $paymentUuid = Uuid::v4();
        $idem = 'inv_' . $invoiceUuid . '_' . $amountDue;
        $base = rtrim($siteUrl, '/');
        $token = (string) ($inv['public_token'] ?? '');
        $success = $this->settings->successUrl() ?: ($base . '/invoice/' . $token . '?paid=1');
        $cancel  = $this->settings->cancelUrl() ?: ($base . '/invoice/' . $token);

        $session = $this->client()->createCheckoutSession([
            'mode' => 'payment',
            'success_url' => $success,
            'cancel_url' => $cancel,
            'client_reference_id' => $invoiceUuid,
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => strtolower($currency),
                    'unit_amount' => $amountDue,
                    'product_data' => ['name' => 'Invoice ' . (string) ($inv['number'] ?? '')],
                ],
            ]],
            'metadata' => ['invoice_uuid' => $invoiceUuid, 'payment_uuid' => $paymentUuid],
            'payment_intent_data' => ['metadata' => ['invoice_uuid' => $invoiceUuid, 'payment_uuid' => $paymentUuid]],
        ], $idem);

        $now = Clock::nowIso();
        $this->db()->run(
            'INSERT INTO payments (uuid, provider, provider_session_id, invoice_uuid, contact_uuid, amount, currency, status, idempotency_key, mode, created_at, updated_at)
             VALUES (:uuid, \'stripe\', :sess, :inv, :contact, :amt, :cur, \'pending\', :idem, :mode, :now, :now)',
            [
                'uuid' => $paymentUuid, 'sess' => (string) ($session['id'] ?? ''), 'inv' => $invoiceUuid,
                'contact' => $this->nullable($inv['contact_uuid'] ?? null), 'amt' => $amountDue, 'cur' => $currency,
                'idem' => $idem, 'mode' => $this->settings->mode(), 'now' => $now,
            ]
        );
        $this->paymentEvent($paymentUuid, $invoiceUuid, 'link_created', 'Payment link created for ' . $this->money($amountDue, $currency));
        $this->platform->invoices()->logEvent($invoiceUuid, 'payment_link', 'Stripe payment link created', $actor);

        return ['url' => (string) ($session['url'] ?? ''), 'payment_id' => $paymentUuid, 'session_id' => (string) ($session['id'] ?? '')];
    }

    // ==================================================================
    // Reconciliation (called from the verified webhook)
    // ==================================================================

    /**
     * Handle a verified Stripe event. Idempotent per event id.
     *
     * @param array<string,mixed> $event
     */
    public function handleEvent(array $event): void
    {
        $eventId = (string) ($event['id'] ?? '');
        $type    = (string) ($event['type'] ?? '');
        if ($eventId === '') {
            return;
        }
        // Idempotency: record the event; skip if already seen.
        $existing = $this->db()->one('SELECT status FROM payment_webhook_events WHERE id = :id', ['id' => $eventId]);
        if ($existing !== null) {
            return;
        }
        $this->db()->run(
            'INSERT INTO payment_webhook_events (id, type, status, received_at) VALUES (:id, :t, \'received\', :now)',
            ['id' => $eventId, 't' => $type, 'now' => Clock::nowIso()]
        );
        $this->settings->note('last_webhook', $type . ' @ ' . Clock::nowIso(), 'system');

        $obj = is_array($event['data']['object'] ?? null) ? $event['data']['object'] : [];
        $status = 'ignored';
        $detail = '';
        try {
            switch ($type) {
                case 'checkout.session.completed':
                    // Only settle if the payment actually succeeded.
                    if (($obj['payment_status'] ?? '') === 'paid') {
                        $detail = $this->reconcile($obj, 'checkout.session.completed');
                        $status = 'processed';
                    } else {
                        $detail = 'session completed but not paid';
                    }
                    break;
                case 'payment_intent.succeeded':
                    $detail = $this->reconcile($obj, 'payment_intent.succeeded');
                    $status = 'processed';
                    break;
                case 'payment_intent.payment_failed':
                    $detail = $this->markFailed($obj);
                    $status = 'processed';
                    break;
                case 'checkout.session.expired':
                    $detail = $this->markExpired($obj);
                    $status = 'processed';
                    break;
                case 'charge.refunded':
                    $detail = $this->reconcileRefund($obj);
                    $status = 'processed';
                    break;
                default:
                    $detail = 'unhandled event type';
            }
        } catch (\Throwable $e) {
            $status = 'error';
            $detail = $e->getMessage();
            $this->settings->note('last_failure', $type . ': ' . $e->getMessage(), 'system');
        }

        $this->db()->run('UPDATE payment_webhook_events SET status = :s, detail = :d, processed_at = :now WHERE id = :id', ['s' => $status, 'd' => mb_substr($detail, 0, 300), 'now' => Clock::nowIso(), 'id' => $eventId]);
    }

    /**
     * Reconcile a successful payment object to its invoice.
     *
     * @param array<string,mixed> $obj checkout session or payment intent
     */
    private function reconcile(array $obj, string $source): string
    {
        $meta        = is_array($obj['metadata'] ?? null) ? $obj['metadata'] : [];
        $invoiceUuid = (string) ($meta['invoice_uuid'] ?? '');
        $paymentUuid = (string) ($meta['payment_uuid'] ?? '');
        // Amount + currency come from the trusted provider object.
        $amount   = (int) ($obj['amount_total'] ?? $obj['amount_received'] ?? $obj['amount'] ?? 0);
        $currency = strtoupper((string) ($obj['currency'] ?? 'GBP'));
        $intentId = (string) ($obj['payment_intent'] ?? $obj['id'] ?? '');
        $sessionId = (string) ($obj['id'] ?? '');

        $payment = $paymentUuid !== '' ? $this->db()->one('SELECT * FROM payments WHERE uuid = :u', ['u' => $paymentUuid]) : null;
        if ($payment === null && $sessionId !== '') {
            $payment = $this->db()->one('SELECT * FROM payments WHERE provider_session_id = :s', ['s' => $sessionId]);
        }
        if ($payment === null) {
            $this->warn('Verified payment with no matching record (invoice ' . $invoiceUuid . ').');

            return 'no matching payment record';
        }
        $paymentUuid = (string) $payment['uuid'];
        $invoiceUuid = $invoiceUuid !== '' ? $invoiceUuid : (string) $payment['invoice_uuid'];

        // Already settled → idempotent no-op.
        if ((string) $payment['status'] === 'succeeded') {
            return 'already reconciled';
        }
        // Anomaly checks — never silently absorb.
        if ($currency !== strtoupper((string) $payment['currency'])) {
            $this->warn('Payment currency mismatch on invoice ' . $invoiceUuid . ' (' . $currency . ' vs ' . $payment['currency'] . ').');
        }
        if ($amount !== (int) $payment['amount']) {
            $this->warn('Payment amount mismatch on invoice ' . $invoiceUuid . ' (' . $amount . ' vs ' . $payment['amount'] . ').');
        }

        return $this->db()->transaction(function () use ($paymentUuid, $invoiceUuid, $amount, $currency, $intentId, $source): string {
            $now = Clock::nowIso();
            $this->db()->run(
                'UPDATE payments SET status = \'succeeded\', provider_payment_id = :pi, paid_at = :now, updated_at = :now WHERE uuid = :u',
                ['pi' => $intentId, 'now' => $now, 'u' => $paymentUuid]
            );
            // Drive invoice paid/part-paid through the single invoicing path.
            $this->platform->invoices()->recordPayment($invoiceUuid, [
                'amount' => $amount / 100,
                'method' => 'stripe',
                'reference' => $intentId,
                'note' => 'Verified via ' . $source,
            ], 'system:stripe');

            $this->paymentEvent($paymentUuid, $invoiceUuid, 'succeeded', 'Payment verified (' . $this->money($amount, $currency) . ')');
            $receipt = $this->generateReceipt($paymentUuid, $invoiceUuid, $amount, $currency);

            // CRM + audit.
            $inv = $this->platform->invoices()->find($invoiceUuid);
            $contact = (string) ($inv['contact_uuid'] ?? '');
            if ($contact !== '') {
                $this->platform->activities()->record('contact', $contact, 'payment.received', 'Payment received: ' . $this->money($amount, $currency) . ' for ' . (string) ($inv['number'] ?? ''), 'system', null, ['invoice' => $invoiceUuid, 'payment' => $paymentUuid]);
            }
            $this->platform->audit()->event('payment.reconciled', 'invoice', $invoiceUuid, 'system:stripe', ['payment' => $paymentUuid, 'amount' => $amount, 'receipt' => $receipt]);
            $this->settings->note('last_payment', $this->money($amount, $currency) . ' @ ' . $now, 'system');

            return 'reconciled ' . $this->money($amount, $currency);
        });
    }

    /** @param array<string,mixed> $obj */
    private function markFailed(array $obj): string
    {
        $meta = is_array($obj['metadata'] ?? null) ? $obj['metadata'] : [];
        $paymentUuid = (string) ($meta['payment_uuid'] ?? '');
        if ($paymentUuid === '') {
            return 'no payment ref';
        }
        $this->db()->run(
            "UPDATE payments SET status = 'failed', failure_code = :c, failure_message = :m, updated_at = :now WHERE uuid = :u AND status <> 'succeeded'",
            ['c' => (string) ($obj['last_payment_error']['code'] ?? ''), 'm' => mb_substr((string) ($obj['last_payment_error']['message'] ?? 'Payment failed'), 0, 200), 'now' => Clock::nowIso(), 'u' => $paymentUuid]
        );
        $this->paymentEvent($paymentUuid, '', 'failed', 'Payment failed');
        $this->warn('A Stripe payment failed (payment ' . $paymentUuid . ').');

        return 'marked failed';
    }

    /** @param array<string,mixed> $obj */
    private function markExpired(array $obj): string
    {
        $sessionId = (string) ($obj['id'] ?? '');
        $this->db()->run("UPDATE payments SET status = 'cancelled', updated_at = :now WHERE provider_session_id = :s AND status = 'pending'", ['now' => Clock::nowIso(), 's' => $sessionId]);

        return 'session expired';
    }

    /** @param array<string,mixed> $charge */
    private function reconcileRefund(array $charge): string
    {
        $intentId = (string) ($charge['payment_intent'] ?? '');
        $refunded = (int) ($charge['amount_refunded'] ?? 0);
        $payment  = $this->db()->one('SELECT * FROM payments WHERE provider_payment_id = :pi', ['pi' => $intentId]);
        if ($payment === null) {
            return 'no payment for refund';
        }
        $paymentUuid = (string) $payment['uuid'];
        $full = $refunded >= (int) $payment['amount'];
        $this->db()->run(
            'UPDATE payments SET amount_refunded = :r, status = :s, updated_at = :now WHERE uuid = :u',
            ['r' => $refunded, 's' => $full ? 'refunded' : 'partially_refunded', 'now' => Clock::nowIso(), 'u' => $paymentUuid]
        );
        // Reduce the invoice's paid amount + reopen status.
        $this->reduceInvoicePaid((string) $payment['invoice_uuid'], $refunded);
        $this->paymentEvent($paymentUuid, (string) $payment['invoice_uuid'], 'refunded', 'Refund of ' . $this->money($refunded, (string) $payment['currency']));
        $this->db()->run("UPDATE payment_refunds SET status = 'succeeded', updated_at = :now WHERE payment_uuid = :u AND status = 'pending'", ['now' => Clock::nowIso(), 'u' => $paymentUuid]);
        $this->platform->audit()->event('payment.refunded', 'invoice', (string) $payment['invoice_uuid'], 'system:stripe', ['payment' => $paymentUuid, 'amount' => $refunded]);

        return 'refund reconciled';
    }

    // ==================================================================
    // Refund (staff-initiated)
    // ==================================================================

    /**
     * @return array<string,mixed>
     */
    public function refund(string $paymentUuid, int $amountPence, string $reason, string $actor): array
    {
        $payment = $this->db()->one('SELECT * FROM payments WHERE uuid = :u', ['u' => $paymentUuid]);
        if ($payment === null) {
            throw new PaymentException(404, 'Payment not found.');
        }
        if ((string) $payment['status'] !== 'succeeded' && (string) $payment['status'] !== 'partially_refunded') {
            throw new PaymentException(409, 'Only a settled payment can be refunded.');
        }
        $remaining = (int) $payment['amount'] - (int) $payment['amount_refunded'];
        $amount = $amountPence > 0 ? min($amountPence, $remaining) : $remaining;
        if ($amount <= 0) {
            throw new PaymentException(422, 'There is nothing left to refund.');
        }
        $refundUuid = Uuid::v4();
        $this->db()->run(
            'INSERT INTO payment_refunds (uuid, payment_uuid, amount, reason, status, created_by, created_at, updated_at)
             VALUES (:uuid, :p, :amt, :reason, \'pending\', :actor, :now, :now)',
            ['uuid' => $refundUuid, 'p' => $paymentUuid, 'amt' => $amount, 'reason' => $reason, 'actor' => $actor, 'now' => Clock::nowIso()]
        );
        $result = $this->client()->createRefund([
            'payment_intent' => (string) $payment['provider_payment_id'],
            'amount' => $amount,
            'metadata' => ['payment_uuid' => $paymentUuid, 'refund_uuid' => $refundUuid],
        ], 'refund_' . $refundUuid);

        $this->db()->run('UPDATE payment_refunds SET provider_refund_id = :rid WHERE uuid = :u', ['rid' => (string) ($result['id'] ?? ''), 'u' => $refundUuid]);
        $this->paymentEvent($paymentUuid, (string) $payment['invoice_uuid'], 'refund_requested', 'Refund requested: ' . $this->money($amount, (string) $payment['currency']) . ' (' . $reason . ')');
        $this->platform->audit()->event('payment.refund_requested', 'invoice', (string) $payment['invoice_uuid'], $actor, ['payment' => $paymentUuid, 'amount' => $amount, 'reason' => $reason]);

        // Stripe refunds usually settle immediately; the charge.refunded webhook
        // confirms. Reflect the requested state now, finalised on webhook.
        return ['ok' => true, 'refund_id' => $refundUuid, 'status' => (string) ($result['status'] ?? 'pending')];
    }

    // ==================================================================
    // Read
    // ==================================================================

    /**
     * @param array<string,mixed> $filters
     * @return list<array<string,mixed>>
     */
    public function list(array $filters = []): array
    {
        $where = ['1 = 1'];
        $params = [];
        if (!empty($filters['invoice_uuid'])) {
            $where[] = 'invoice_uuid = :inv';
            $params['inv'] = (string) $filters['invoice_uuid'];
        }
        if (!empty($filters['status'])) {
            $where[] = 'status = :s';
            $params['s'] = (string) $filters['status'];
        }
        $params['l'] = (int) ($filters['limit'] ?? 100);

        return $this->db()->all('SELECT * FROM payments WHERE ' . implode(' AND ', $where) . ' ORDER BY created_at DESC LIMIT :l', $params);
    }

    /** @return array<string,mixed>|null */
    public function find(string $uuid): ?array
    {
        $p = $this->db()->one('SELECT * FROM payments WHERE uuid = :u', ['u' => $uuid]);
        if ($p === null) {
            return null;
        }
        $p['events'] = $this->db()->all('SELECT * FROM payment_events WHERE payment_uuid = :u ORDER BY created_at ASC', ['u' => $uuid]);
        $p['refunds'] = $this->db()->all('SELECT * FROM payment_refunds WHERE payment_uuid = :u ORDER BY created_at DESC', ['u' => $uuid]);
        $p['receipt'] = $this->db()->one('SELECT * FROM payment_receipts WHERE payment_uuid = :u ORDER BY created_at DESC LIMIT 1', ['u' => $uuid]);

        return $p;
    }

    /**
     * Read a stored receipt PDF with an integrity check.
     *
     * @return array{filename:string,bytes:string}
     */
    public function downloadReceipt(string $paymentUuid): array
    {
        $receipt = $this->db()->one('SELECT * FROM payment_receipts WHERE payment_uuid = :u ORDER BY created_at DESC LIMIT 1', ['u' => $paymentUuid]);
        if ($receipt === null) {
            throw new PaymentException(404, 'No receipt available.');
        }
        $base = $this->platform->storageDir() . '/receipts';
        $real = realpath($base . '/' . (string) $receipt['storage_key']);
        $baseReal = realpath($base);
        if ($real === false || $baseReal === false || strncmp($real, $baseReal . DIRECTORY_SEPARATOR, strlen($baseReal) + 1) !== 0) {
            throw new PaymentException(404, 'Receipt not found.');
        }
        $bytes = file_get_contents($real);
        if ($bytes === false || hash('sha256', $bytes) !== (string) $receipt['sha256']) {
            throw new PaymentException(409, 'The stored receipt failed its integrity check.');
        }

        return ['filename' => 'RECEIPT-' . preg_replace('/[^A-Za-z0-9\-]/', '', (string) $receipt['number']) . '.pdf', 'bytes' => $bytes];
    }

    // ==================================================================
    // Internals
    // ==================================================================

    private function generateReceipt(string $paymentUuid, string $invoiceUuid, int $amount, string $currency): string
    {
        $inv = $this->platform->invoices()->find($invoiceUuid) ?? [];
        $number = $this->allocateReceiptNumber();
        $html = (new ReceiptPdfTemplate())->html([
            'number' => $number, 'currency' => $currency, 'amount' => $amount,
            'invoice_number' => (string) ($inv['number'] ?? ''), 'client' => (string) ($inv['bill_to_name'] ?? ''),
            'seller' => (string) ($inv['seller_name'] ?? 'Breakfast'), 'method' => 'Card (Stripe)',
            'paid_at' => Clock::nowIso(), 'amount_remaining' => (int) ($inv['total'] ?? 0) - (int) ($inv['amount_paid'] ?? 0),
        ]);
        $bytes = (new ReceiptPdfRenderer())->render($html);

        $base = $this->platform->storageDir() . '/receipts';
        $dir  = $base . '/' . $paymentUuid;
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return '';
        }
        $key = $paymentUuid . '/' . bin2hex(random_bytes(12)) . '.pdf';
        $path = $base . '/' . $key;
        if (file_put_contents($path, $bytes) === false) {
            return '';
        }
        @chmod($path, 0600);
        $this->db()->run(
            'INSERT INTO payment_receipts (uuid, number, payment_uuid, invoice_uuid, amount, currency, storage_key, sha256, created_at)
             VALUES (:uuid, :num, :p, :inv, :amt, :cur, :key, :hash, :now)',
            ['uuid' => Uuid::v4(), 'num' => $number, 'p' => $paymentUuid, 'inv' => $invoiceUuid, 'amt' => $amount, 'cur' => $currency, 'key' => $key, 'hash' => hash('sha256', $bytes), 'now' => Clock::nowIso()]
        );
        $this->db()->run('UPDATE payments SET receipt_number = :n WHERE uuid = :u', ['n' => $number, 'u' => $paymentUuid]);

        return $number;
    }

    private function allocateReceiptNumber(): string
    {
        $year = (int) date('Y');
        $this->db()->run(
            'INSERT INTO payment_sequences (prefix, year, next_seq) VALUES (\'RCT\', :y, 2)
             ON CONFLICT(prefix, year) DO UPDATE SET next_seq = next_seq + 1',
            ['y' => $year]
        );
        $seq = (int) $this->db()->scalar('SELECT next_seq - 1 FROM payment_sequences WHERE prefix = \'RCT\' AND year = :y', ['y' => $year]);

        return sprintf('RCT-%d-%04d', $year, $seq);
    }

    private function reduceInvoicePaid(string $invoiceUuid, int $refunded): void
    {
        $inv = $this->platform->invoices()->find($invoiceUuid);
        if ($inv === null) {
            return;
        }
        $paid = max(0, (int) $inv['amount_paid'] - $refunded);
        $status = $paid <= 0 ? 'sent' : ($paid >= (int) $inv['total'] ? 'paid' : 'partial');
        $this->db()->run('UPDATE invoices SET amount_paid = :p, status = :s, updated_at = :now WHERE uuid = :u', ['p' => $paid, 's' => $status, 'now' => Clock::nowIso(), 'u' => $invoiceUuid]);
        $this->platform->invoices()->logEvent($invoiceUuid, 'refund', 'Refund applied', 'system:stripe');
    }

    private function paymentEvent(string $paymentUuid, string $invoiceUuid, string $type, string $detail): void
    {
        $this->db()->run(
            'INSERT INTO payment_events (uuid, payment_uuid, invoice_uuid, type, detail, created_at) VALUES (:uuid, :p, :inv, :t, :d, :now)',
            ['uuid' => Uuid::v4(), 'p' => $paymentUuid, 'inv' => $invoiceUuid, 't' => $type, 'd' => $detail, 'now' => Clock::nowIso()]
        );
    }

    private function warn(string $message): void
    {
        $this->platform->audit()->event('payment.anomaly', 'invoice', 'stripe', 'system:stripe', ['warning' => $message]);
        $this->settings->note('last_failure', $message, 'system');
    }

    private function money(int $pence, string $currency): string
    {
        $sym = $currency === 'GBP' ? '£' : '';

        return $sym . number_format($pence / 100, 2);
    }

    private function nullable(mixed $value): ?string
    {
        $v = trim((string) ($value ?? ''));

        return $v === '' ? null : $v;
    }
}
