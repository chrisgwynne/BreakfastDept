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

    private function store(): \Breakfast\Platform\Support\FileStore
    {
        return $this->platform->fileStore();
    }

    private function client(): StripeGateway
    {
        // Browser-test seam: a single-threaded dev server cannot make a live (or
        // self-directed) HTTP call, so an explicit non-production flag swaps in an
        // in-process fake. Production never takes this branch.
        if (getenv('BF_STRIPE_MOCK') === '1' && !$this->platform->isProduction()) {
            return new FakeStripeGateway();
        }
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
        $this->store()->put('payments', [
            'uuid'                => $paymentUuid,
            'provider'            => 'stripe',
            'provider_session_id' => (string) ($session['id'] ?? ''),
            'provider_payment_id' => null,
            'invoice_uuid'        => $invoiceUuid,
            'contact_uuid'        => $this->nullable($inv['contact_uuid'] ?? null),
            'amount'              => $amountDue,
            'currency'            => $currency,
            'status'              => 'pending',
            'method_summary'      => null,
            'fee'                 => 0,
            'amount_refunded'     => 0,
            'failure_code'        => null,
            'failure_message'     => null,
            'idempotency_key'     => $idem,
            'receipt_number'      => null,
            'mode'                => $this->settings->mode(),
            'created_at'          => $now,
            'paid_at'             => null,
            'updated_at'          => $now,
        ]);
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
        // Idempotency: record the event; skip if already seen. putIfAbsent is the
        // file-store equivalent of the UNIQUE primary key on the event id.
        $eventKey = sha1($eventId);
        $fresh = $this->store()->putIfAbsent('payment_webhook_events', $eventKey, [
            'uuid'        => $eventKey,
            'id'          => $eventId,
            'type'        => $type,
            'status'      => 'received',
            'detail'      => null,
            'received_at' => Clock::nowIso(),
            'processed_at' => null,
        ]);
        if ($fresh === false) {
            return;
        }
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

        $this->store()->update('payment_webhook_events', sha1($eventId), static function (array $row) use ($status, $detail): array {
            $row['status']       = $status;
            $row['detail']       = mb_substr($detail, 0, 300);
            $row['processed_at'] = Clock::nowIso();

            return $row;
        });
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

        $payment = $paymentUuid !== '' ? $this->store()->find('payments', $paymentUuid) : null;
        if ($payment === null && $sessionId !== '') {
            $payment = $this->findPaymentBy('provider_session_id', $sessionId);
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

        $now = Clock::nowIso();
        $this->store()->update('payments', $paymentUuid, static function (array $row) use ($intentId, $now): array {
            $row['status']              = 'succeeded';
            $row['provider_payment_id'] = $intentId;
            $row['paid_at']             = $now;
            $row['updated_at']          = $now;

            return $row;
        });
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
    }

    /** @param array<string,mixed> $obj */
    private function markFailed(array $obj): string
    {
        $meta = is_array($obj['metadata'] ?? null) ? $obj['metadata'] : [];
        $paymentUuid = (string) ($meta['payment_uuid'] ?? '');
        if ($paymentUuid === '') {
            return 'no payment ref';
        }
        $code    = (string) ($obj['last_payment_error']['code'] ?? '');
        $message = mb_substr((string) ($obj['last_payment_error']['message'] ?? 'Payment failed'), 0, 200);
        $this->store()->update('payments', $paymentUuid, static function (array $row) use ($code, $message): array {
            if ((string) ($row['status'] ?? '') === 'succeeded') {
                return $row;
            }
            $row['status']          = 'failed';
            $row['failure_code']    = $code;
            $row['failure_message'] = $message;
            $row['updated_at']      = Clock::nowIso();

            return $row;
        });
        $this->paymentEvent($paymentUuid, '', 'failed', 'Payment failed');
        $this->warn('A Stripe payment failed (payment ' . $paymentUuid . ').');

        return 'marked failed';
    }

    /** @param array<string,mixed> $obj */
    private function markExpired(array $obj): string
    {
        $sessionId = (string) ($obj['id'] ?? '');
        $payment = $this->findPaymentBy('provider_session_id', $sessionId);
        if ($payment !== null && (string) ($payment['status'] ?? '') === 'pending') {
            $this->store()->update('payments', (string) $payment['uuid'], static function (array $row): array {
                $row['status']     = 'cancelled';
                $row['updated_at'] = Clock::nowIso();

                return $row;
            });
        }

        return 'session expired';
    }

    /** @param array<string,mixed> $charge */
    private function reconcileRefund(array $charge): string
    {
        $intentId = (string) ($charge['payment_intent'] ?? '');
        $refunded = (int) ($charge['amount_refunded'] ?? 0);
        $payment  = $this->findPaymentBy('provider_payment_id', $intentId);
        if ($payment === null) {
            return 'no payment for refund';
        }
        $paymentUuid = (string) $payment['uuid'];
        $full = $refunded >= (int) $payment['amount'];
        $this->store()->update('payments', $paymentUuid, static function (array $row) use ($refunded, $full): array {
            $row['amount_refunded'] = $refunded;
            $row['status']          = $full ? 'refunded' : 'partially_refunded';
            $row['updated_at']      = Clock::nowIso();

            return $row;
        });
        // Reduce the invoice's paid amount + reopen status.
        $this->reduceInvoicePaid((string) $payment['invoice_uuid'], $refunded);
        $this->paymentEvent($paymentUuid, (string) $payment['invoice_uuid'], 'refunded', 'Refund of ' . $this->money($refunded, (string) $payment['currency']));
        foreach ($this->store()->all('payment_refunds') as $refund) {
            if ((string) ($refund['payment_uuid'] ?? '') === $paymentUuid && (string) ($refund['status'] ?? '') === 'pending') {
                $this->store()->update('payment_refunds', (string) $refund['uuid'], static function (array $row): array {
                    $row['status']     = 'succeeded';
                    $row['updated_at'] = Clock::nowIso();

                    return $row;
                });
            }
        }
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
        $payment = $this->store()->find('payments', $paymentUuid);
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
        $now = Clock::nowIso();
        $this->store()->put('payment_refunds', [
            'uuid'               => $refundUuid,
            'payment_uuid'       => $paymentUuid,
            'amount'             => $amount,
            'reason'             => $reason,
            'status'             => 'pending',
            'provider_refund_id' => null,
            'created_by'         => $actor,
            'created_at'         => $now,
            'updated_at'         => $now,
        ]);
        $result = $this->client()->createRefund([
            'payment_intent' => (string) $payment['provider_payment_id'],
            'amount' => $amount,
            'metadata' => ['payment_uuid' => $paymentUuid, 'refund_uuid' => $refundUuid],
        ], 'refund_' . $refundUuid);

        $refundId = (string) ($result['id'] ?? '');
        $this->store()->update('payment_refunds', $refundUuid, static function (array $row) use ($refundId): array {
            $row['provider_refund_id'] = $refundId;

            return $row;
        });
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
        $rows = $this->store()->all('payments');
        if (!empty($filters['invoice_uuid'])) {
            $rows = array_filter($rows, static fn (array $r): bool => (string) ($r['invoice_uuid'] ?? '') === (string) $filters['invoice_uuid']);
        }
        if (!empty($filters['status'])) {
            $rows = array_filter($rows, static fn (array $r): bool => (string) ($r['status'] ?? '') === (string) $filters['status']);
        }
        $rows = array_values($rows);
        usort($rows, static fn ($a, $b) => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));

        return array_slice($rows, 0, (int) ($filters['limit'] ?? 100));
    }

    /** @return array<string,mixed>|null */
    public function find(string $uuid): ?array
    {
        $p = $this->store()->find('payments', $uuid);
        if ($p === null) {
            return null;
        }
        $events = array_values(array_filter($this->store()->all('payment_events'), static fn (array $r): bool => (string) ($r['payment_uuid'] ?? '') === $uuid));
        usort($events, static fn ($a, $b) => strcmp((string) ($a['created_at'] ?? ''), (string) ($b['created_at'] ?? '')));

        $refunds = array_values(array_filter($this->store()->all('payment_refunds'), static fn (array $r): bool => (string) ($r['payment_uuid'] ?? '') === $uuid));
        usort($refunds, static fn ($a, $b) => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));

        $receipts = array_values(array_filter($this->store()->all('payment_receipts'), static fn (array $r): bool => (string) ($r['payment_uuid'] ?? '') === $uuid));
        usort($receipts, static fn ($a, $b) => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));

        $p['events']  = $events;
        $p['refunds'] = $refunds;
        $p['receipt'] = $receipts[0] ?? null;

        return $p;
    }

    /**
     * Find a single payment by an indexed field (session/intent id).
     *
     * @return array<string,mixed>|null
     */
    private function findPaymentBy(string $field, string $value): ?array
    {
        if ($value === '') {
            return null;
        }
        foreach ($this->store()->all('payments') as $row) {
            if ((string) ($row[$field] ?? '') === $value) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Read a stored receipt PDF with an integrity check.
     *
     * @return array{filename:string,bytes:string}
     */
    public function downloadReceipt(string $paymentUuid): array
    {
        $receipts = array_values(array_filter($this->store()->all('payment_receipts'), static fn (array $r): bool => (string) ($r['payment_uuid'] ?? '') === $paymentUuid));
        usort($receipts, static fn ($a, $b) => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));
        $receipt = $receipts[0] ?? null;
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
        $this->store()->put('payment_receipts', [
            'uuid'         => Uuid::v4(),
            'number'       => $number,
            'payment_uuid' => $paymentUuid,
            'invoice_uuid' => $invoiceUuid,
            'amount'       => $amount,
            'currency'     => $currency,
            'storage_key'  => $key,
            'sha256'       => hash('sha256', $bytes),
            'created_at'   => Clock::nowIso(),
        ]);
        $this->store()->update('payments', $paymentUuid, static function (array $row) use ($number): array {
            $row['receipt_number'] = $number;

            return $row;
        });

        return $number;
    }

    private function allocateReceiptNumber(): string
    {
        $year = (int) date('Y');
        $seq  = $this->store()->bump('payment_sequences', 'RCT-' . $year);

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
        $this->platform->fileStore()->update('invoices', $invoiceUuid, static function (array $row) use ($paid, $status): array {
            $row['amount_paid'] = $paid;
            $row['status']      = $status;
            $row['updated_at']  = Clock::nowIso();

            return $row;
        });
        $this->platform->invoices()->logEvent($invoiceUuid, 'refund', 'Refund applied', 'system:stripe');
    }

    private function paymentEvent(string $paymentUuid, string $invoiceUuid, string $type, string $detail): void
    {
        $this->store()->put('payment_events', [
            'uuid'         => Uuid::v4(),
            'payment_uuid' => $paymentUuid,
            'invoice_uuid' => $invoiceUuid,
            'type'         => $type,
            'detail'       => $detail,
            'created_at'   => Clock::nowIso(),
        ]);
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
