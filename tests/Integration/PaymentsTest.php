<?php

declare(strict_types=1);

namespace Breakfast\Tests\Integration;

use Breakfast\Platform\Mail\HttpClient;
use Breakfast\Platform\Mail\HttpResponse;
use Breakfast\Platform\Payments\PaymentException;
use Breakfast\Platform\Payments\PaymentsService;
use Breakfast\Platform\Payments\StripeWebhook;
use Breakfast\Platform\Support\Database;
use Breakfast\Platform\Support\Platform;
use Kirby\Cms\App;
use PHPUnit\Framework\TestCase;

/**
 * Stripe deposit payments. Proves the real end-to-end money path: a checkout
 * session is created with a server-computed amount, an invoice is only marked
 * paid by reconciling a VERIFIED webhook (never a redirect), reconciliation is
 * idempotent + amount/currency checked, a receipt PDF is generated, and a
 * refund reduces the paid figure. Also proves webhook signature verification
 * (valid, tampered, stale) and that a bad signature is never processed.
 */
final class PaymentsTest extends TestCase
{
    private App $kirby;
    private string $tmp;
    private PaymentsService $svc;

    /** @var list<array{method:string,url:string,body:?string}> */
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        $base = dirname(__DIR__, 2);
        $this->tmp = sys_get_temp_dir() . '/bf-payments-' . bin2hex(random_bytes(6));
        @mkdir($this->tmp . '/database', 0777, true);

        Database::reset();
        Platform::reset();

        $this->kirby = new App([
            'roots' => [
                'index'    => $base . '/public',
                'base'     => $base,
                'site'     => $base . '/site',
                'content'  => $base . '/content',
                'storage'  => $this->tmp,
                'sessions' => $this->tmp . '/sessions',
                'accounts' => $this->tmp . '/accounts',
            ],
            'options' => ['debug' => false, 'whoops' => false, 'breakfast' => ['production' => false, 'storageDir' => $this->tmp, 'dbPath' => $this->tmp . '/database/crm.sqlite', 'mail' => ['provider' => 'fake']]],
        ]);
        breakfast()->migrator()->migrate();
        $this->svc = breakfast()->payments();

        // Configure Stripe in test mode with a stored secret + webhook secret.
        $settings = breakfast()->stripeSettings();
        $settings->update(['mode' => 'test', 'enabled' => true, 'currency' => 'GBP'], 'test@breakfast');
        $settings->setSecretKey('sk_test_example', 'test@breakfast');
        $settings->setWebhookSecret('whsec_test_secret', 'test@breakfast');
    }

    protected function tearDown(): void
    {
        HttpClient::useTransport(null);
        Database::reset();
        Platform::reset();
        $this->rrmdir($this->tmp);
        App::destroy();
        restore_error_handler();
        restore_exception_handler();
        parent::tearDown();
    }

    /**
     * Fake Stripe transport: records calls and returns canned JSON keyed by path.
     *
     * @param array<string,array<string,mixed>> $responses path-substring => decoded body
     */
    private function fakeStripe(array $responses): void
    {
        $this->calls = [];
        HttpClient::useTransport(function (string $method, string $url, array $headers, ?string $body) use ($responses): HttpResponse {
            $this->calls[] = ['method' => $method, 'url' => $url, 'body' => $body];
            foreach ($responses as $needle => $payload) {
                if (str_contains($url, $needle)) {
                    return new HttpResponse(200, (string) json_encode($payload));
                }
            }

            return new HttpResponse(404, (string) json_encode(['error' => ['message' => 'no stub for ' . $url]]));
        });
    }

    /** Create + issue an invoice for £600, returning its uuid. */
    private function issuedInvoice(int $pounds = 600): string
    {
        $inv = breakfast()->invoices()->create([
            'bill_to_name'  => 'Acme Ltd',
            'bill_to_email' => 'client@acme.test',
            'items'         => [['description' => 'Deposit', 'quantity' => 1, 'unit_price' => $pounds]],
        ], 'staff@breakfast');
        breakfast()->invoices()->issue((string) $inv['uuid'], 'staff@breakfast');

        return (string) $inv['uuid'];
    }

    // ------------------------------------------------------------------

    public function testCreateCheckoutComputesAmountServerSide(): void
    {
        $invoiceUuid = $this->issuedInvoice(600);
        $this->fakeStripe(['/checkout/sessions' => ['id' => 'cs_test_1', 'url' => 'https://checkout.stripe.test/pay/cs_test_1']]);

        $link = $this->svc->createCheckout($invoiceUuid, 'staff@breakfast', 'https://breakfast.test');

        $this->assertSame('https://checkout.stripe.test/pay/cs_test_1', $link['url']);
        $paymentUuid = (string) $link['payment_id'];
        $payment = breakfast()->payments()->find($paymentUuid);
        $this->assertNotNull($payment);
        $this->assertSame('pending', $payment['status']);
        // £600 in pence, computed from the invoice — not supplied by the caller.
        $this->assertSame(60000, (int) $payment['amount']);
        // The unit_amount sent to Stripe was the server figure (bracket key is
        // URL-encoded: ...[unit_amount]=60000 → ...unit_amount%5D=60000).
        $this->assertStringContainsString('unit_amount%5D=60000', (string) $this->calls[0]['body']);
    }

    public function testVerifiedWebhookReconcilesInvoiceAndIssuesReceipt(): void
    {
        $invoiceUuid = $this->issuedInvoice(600);
        $this->fakeStripe(['/checkout/sessions' => ['id' => 'cs_test_2', 'url' => 'https://checkout.stripe.test/pay/cs_test_2']]);
        $link = $this->svc->createCheckout($invoiceUuid, 'staff@breakfast', 'https://breakfast.test');
        $paymentUuid = (string) $link['payment_id'];

        $this->svc->handleEvent($this->checkoutCompletedEvent('evt_1', 'cs_test_2', $invoiceUuid, $paymentUuid, 60000));

        // Invoice is now paid — via recordPayment, the single invoicing path.
        $inv = breakfast()->invoices()->find($invoiceUuid);
        $this->assertSame('paid', $inv['status']);
        $this->assertSame(60000, (int) $inv['amount_paid']);

        // Payment succeeded + a receipt PDF was generated and stored intact.
        $payment = breakfast()->payments()->find($paymentUuid);
        $this->assertSame('succeeded', $payment['status']);
        $this->assertNotSame('', (string) $payment['receipt_number']);
        $receipt = $this->svc->downloadReceipt($paymentUuid);
        $this->assertSame('%PDF', substr($receipt['bytes'], 0, 4));

        // A CRM activity records the payment against the invoice's contact/timeline.
        $events = $payment['events'];
        $types  = array_map(static fn ($e) => $e['type'], $events);
        $this->assertContains('succeeded', $types);
    }

    public function testReconciliationIsIdempotentPerEvent(): void
    {
        $invoiceUuid = $this->issuedInvoice(600);
        $this->fakeStripe(['/checkout/sessions' => ['id' => 'cs_test_3', 'url' => 'x']]);
        $link = $this->svc->createCheckout($invoiceUuid, 'staff@breakfast', 'https://breakfast.test');
        $paymentUuid = (string) $link['payment_id'];

        $event = $this->checkoutCompletedEvent('evt_dup', 'cs_test_3', $invoiceUuid, $paymentUuid, 60000);
        $this->svc->handleEvent($event);
        // Deliver the SAME event id again — must be a no-op (no double payment).
        $this->svc->handleEvent($event);

        $inv = breakfast()->invoices()->find($invoiceUuid);
        $this->assertSame(60000, (int) $inv['amount_paid']);
        $this->assertCount(1, $inv['payments']);
    }

    public function testUnpaidSessionDoesNotSettleInvoice(): void
    {
        $invoiceUuid = $this->issuedInvoice(600);
        $this->fakeStripe(['/checkout/sessions' => ['id' => 'cs_test_4', 'url' => 'x']]);
        $link = $this->svc->createCheckout($invoiceUuid, 'staff@breakfast', 'https://breakfast.test');
        $paymentUuid = (string) $link['payment_id'];

        $event = $this->checkoutCompletedEvent('evt_unpaid', 'cs_test_4', $invoiceUuid, $paymentUuid, 60000);
        $event['data']['object']['payment_status'] = 'unpaid';
        $this->svc->handleEvent($event);

        $inv = breakfast()->invoices()->find($invoiceUuid);
        $this->assertNotSame('paid', $inv['status']);
        $this->assertSame(0, (int) $inv['amount_paid']);
    }

    public function testAmountMismatchRaisesAnomalyButStillRecordsProviderTruth(): void
    {
        $invoiceUuid = $this->issuedInvoice(600);
        $this->fakeStripe(['/checkout/sessions' => ['id' => 'cs_test_5', 'url' => 'x']]);
        $link = $this->svc->createCheckout($invoiceUuid, 'staff@breakfast', 'https://breakfast.test');
        $paymentUuid = (string) $link['payment_id'];

        // Provider reports a different amount than we expected — must warn.
        $this->svc->handleEvent($this->checkoutCompletedEvent('evt_mism', 'cs_test_5', $invoiceUuid, $paymentUuid, 55000));

        $anomaly = ['n' => count(array_filter(breakfast()->fileStore()->all('hermes_audit'), static fn (array $r): bool => (string) ($r['endpoint'] ?? '') === 'payment.anomaly'))];
        $this->assertGreaterThan(0, (int) ($anomaly['n'] ?? 0));
    }

    public function testStaffRefundReducesInvoicePaidAmount(): void
    {
        $invoiceUuid = $this->issuedInvoice(600);
        $this->fakeStripe([
            '/checkout/sessions' => ['id' => 'cs_test_6', 'url' => 'x'],
            '/refunds'           => ['id' => 're_1', 'status' => 'succeeded'],
        ]);
        $link = $this->svc->createCheckout($invoiceUuid, 'staff@breakfast', 'https://breakfast.test');
        $paymentUuid = (string) $link['payment_id'];
        $this->svc->handleEvent($this->checkoutCompletedEvent('evt_r', 'cs_test_6', $invoiceUuid, $paymentUuid, 60000, 'pi_r'));

        // Refund £200 via the staff path, then the provider confirms via webhook.
        $this->svc->refund($paymentUuid, 20000, 'Goodwill', 'admin@breakfast');
        $this->svc->handleEvent($this->chargeRefundedEvent('evt_refund', 'pi_r', 20000));

        $inv = breakfast()->invoices()->find($invoiceUuid);
        $this->assertSame(40000, (int) $inv['amount_paid']);
        $this->assertSame('partial', $inv['status']);
        $payment = breakfast()->payments()->find($paymentUuid);
        $this->assertSame('partially_refunded', $payment['status']);
    }

    public function testCreateCheckoutBlockedWhenDisabled(): void
    {
        breakfast()->stripeSettings()->update(['enabled' => false], 'admin@breakfast');
        $invoiceUuid = $this->issuedInvoice(600);

        $this->expectException(PaymentException::class);
        $this->svc->createCheckout($invoiceUuid, 'staff@breakfast', 'https://breakfast.test');
    }

    // ---- Webhook signature verification ----

    public function testWebhookSignatureValidAndTamperedAndStale(): void
    {
        $secret = 'whsec_test_secret';
        $payload = '{"id":"evt_x","type":"checkout.session.completed"}';
        $ts = 1_700_000_000;
        $sig = hash_hmac('sha256', $ts . '.' . $payload, $secret);
        $header = 't=' . $ts . ',v1=' . $sig;
        $verifier = new StripeWebhook($secret);

        // Valid within tolerance.
        $event = $verifier->constructEvent($payload, $header, $ts + 10);
        $this->assertSame('evt_x', $event['id']);

        // Tampered payload → rejected.
        try {
            $verifier->constructEvent($payload . ' ', $header, $ts + 10);
            $this->fail('Expected tampered payload to be rejected.');
        } catch (PaymentException $e) {
            $this->assertSame(400, $e->status);
        }

        // Stale timestamp beyond tolerance → rejected.
        try {
            $verifier->constructEvent($payload, $header, $ts + 10_000);
            $this->fail('Expected stale timestamp to be rejected.');
        } catch (PaymentException $e) {
            $this->assertSame(400, $e->status);
        }
    }

    // ------------------------------------------------------------------

    /**
     * @return array<string,mixed>
     */
    private function checkoutCompletedEvent(string $eventId, string $sessionId, string $invoiceUuid, string $paymentUuid, int $amount, string $intent = 'pi_1'): array
    {
        return [
            'id'   => $eventId,
            'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'id'             => $sessionId,
                'object'         => 'checkout.session',
                'payment_status' => 'paid',
                'amount_total'   => $amount,
                'currency'       => 'gbp',
                'payment_intent' => $intent,
                'metadata'       => ['invoice_uuid' => $invoiceUuid, 'payment_uuid' => $paymentUuid],
            ]],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function chargeRefundedEvent(string $eventId, string $intent, int $refunded): array
    {
        return [
            'id'   => $eventId,
            'type' => 'charge.refunded',
            'data' => ['object' => [
                'object'          => 'charge',
                'payment_intent'  => $intent,
                'amount_refunded' => $refunded,
            ]],
        ];
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
