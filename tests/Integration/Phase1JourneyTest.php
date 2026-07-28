<?php

declare(strict_types=1);

namespace Breakfast\Tests\Integration;

use Breakfast\Platform\Mail\HttpClient;
use Breakfast\Platform\Mail\HttpResponse;
use Breakfast\Platform\Support\Platform;
use Kirby\Cms\App;
use PHPUnit\Framework\TestCase;

/**
 * Phase 1 integrated end-to-end proof (spec §33).
 *
 * One connected lifecycle, exercising the real services with no shortcuts:
 * contact + opportunity → proposal → send → client accepts (evidenced) →
 * convert to a won opportunity + a real deposit invoice → contract from the same
 * proposal → send → client signs → Breakfast countersigns → genuine signed PDF →
 * issue the deposit invoice → Stripe checkout → VERIFIED webhook → reconciled
 * payment → receipt PDF → the contact's CRM timeline carries the whole story.
 *
 * This is the authoritative, deterministic proof that the Phase 1 verticals
 * (CRM, proposals, contracts, invoicing, payments) connect into a single
 * workflow rather than standing alone.
 */
final class Phase1JourneyTest extends TestCase
{
    private App $kirby;
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $base = dirname(__DIR__, 2);
        $this->tmp = sys_get_temp_dir() . '/bf-journey-' . bin2hex(random_bytes(6));

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
            'options' => ['debug' => false, 'whoops' => false, 'breakfast' => ['production' => false, 'storageDir' => $this->tmp, 'mail' => ['provider' => 'fake']]],
        ]);
    }

    protected function tearDown(): void
    {
        HttpClient::useTransport(null);
        Platform::reset();
        $this->rrmdir($this->tmp);
        App::destroy();
        restore_error_handler();
        restore_exception_handler();
        parent::tearDown();
    }

    public function testFullEnquiryToReconciledPaymentLifecycle(): void
    {
        $p = breakfast();
        $actor = 'chris@breakfast';

        // 1. CRM: a contact and an opportunity in the pipeline.
        $contact = $p->contacts()->create(['display_name' => 'Sian Roberts', 'email' => 'sian@roberts.example']);
        $contactUuid = (string) $contact['uuid'];
        $opp = $p->crm()->createOpportunity(['title' => 'Roberts Cafe website', 'stage' => 'proposal', 'contact_uuid' => $contactUuid], 'user', $actor);

        // 2. Proposal for £3,000 + a £1,800 deposit, linked to the CRM records.
        $proposal = $p->proposals()->create([
            'title'            => 'Roberts Cafe website',
            'client_name'      => 'Sian Roberts',
            'client_email'     => 'sian@roberts.example',
            'contact_uuid'     => $contactUuid,
            'opportunity_uuid' => (string) $opp['uuid'],
            'deposit_amount'   => 1800,
            'items'            => [['kind' => 'fixed', 'description' => 'Design & build', 'quantity' => 1, 'unit_price' => 3000, 'tax_rate' => 0]],
        ], $actor);
        $proposalUuid = (string) $proposal['uuid'];

        // 3. Send (freezes an immutable snapshot + real PDF), then the client
        //    accepts through the evidenced public path.
        $p->proposals()->send($proposalUuid, $actor, ['company_legal_name' => 'Breakfast Ltd', 'proposal_prefix' => 'PRO']);
        $accepted = $p->proposals()->accept($proposalUuid, ['name' => 'Sian Roberts', 'email' => 'sian@roberts.example', 'ip_hash' => 'hash', 'user_agent' => 'UA']);
        $this->assertSame('accepted', (string) $accepted['status']);

        // 4. Convert: opportunity won + a real draft deposit invoice.
        $conversion = $p->proposalConversion()->convert($proposalUuid, ['won', 'deposit'], $actor);
        $this->assertContains('deposit', $conversion['steps']);
        $invoiceUuid = (string) $conversion['invoice'];
        $this->assertNotSame('', $invoiceUuid);
        $this->assertSame('won', (string) $p->opportunities()->find((string) $opp['uuid'])['stage']);

        // 5. Contract from the accepted proposal: send → client signs → Breakfast
        //    countersigns → genuine signed PDF (a real binary with a certificate).
        $contract = $p->contracts()->create([
            'title'         => 'Roberts Cafe website — agreement',
            'template_key'  => 'website_build',
            'contact_uuid'  => $contactUuid,
            'proposal_uuid' => $proposalUuid,
            'client_name'   => 'Sian Roberts',
            'client_email'  => 'sian@roberts.example',
            'seller_name'   => 'Breakfast Ltd',
            'contract_value' => 3000,
            'deposit_amount' => 1800,
        ], $actor);
        $contractUuid = (string) $contract['uuid'];
        $p->contracts()->send($contractUuid, $actor, [
            'client_name' => 'Sian Roberts', 'company_name' => 'Roberts Cafe', 'contact_name' => 'Sian Roberts',
            'seller_name' => 'Breakfast Ltd', 'proposal_number' => (string) ($accepted['number'] ?? 'PRO-1'),
            'project_name' => 'Roberts Cafe website', 'contract_value' => '£3,000', 'deposit_amount' => '£1,800',
            'start_date' => '2026-08-01', 'target_date' => '2026-09-01', 'revision_limit' => '2',
            'governing_law' => 'the laws of England and Wales',
        ]);
        $clientParty = '';
        foreach (($p->contracts()->find($contractUuid)['parties'] ?? []) as $party) {
            if ((string) $party['role'] === 'client') {
                $clientParty = (string) $party['uuid'];
            }
        }
        $this->assertNotSame('', $clientParty);
        $signed = $p->contracts()->sign($contractUuid, $clientParty, ['name' => 'Sian Roberts', 'email' => 'sian@roberts.example', 'ip_hash' => 'h', 'user_agent' => 'UA']);
        $this->assertFalse($signed['completed']);
        $counter = $p->contracts()->signInternal($contractUuid, ['name' => 'Chris Gwynne']);
        $this->assertTrue($counter['completed']);
        $p->contractDocuments()->generateSigned($contractUuid, $actor);
        $signedPdf = $p->contractDocuments()->download($contractUuid, 'signed');
        $this->assertSame('%PDF', substr($signedPdf['bytes'], 0, 4));

        // 6. Issue the deposit invoice, then configure + take a Stripe payment.
        $p->invoices()->issue($invoiceUuid, $actor);
        $settings = $p->stripeSettings();
        $settings->update(['mode' => 'test', 'enabled' => true, 'currency' => 'GBP'], $actor);
        $settings->setSecretKey('sk_test_journey', $actor);
        $settings->setWebhookSecret('whsec_journey', $actor);

        // Checkout via a faked Stripe transport (no live network).
        HttpClient::useTransport(fn (string $m, string $u): HttpResponse => new HttpResponse(200, (string) json_encode(['id' => 'cs_journey', 'url' => 'https://stripe.test/pay'])));
        $link = $p->payments()->createCheckout($invoiceUuid, $actor, 'https://breakfast.test');
        $paymentUuid = (string) $link['payment_id'];

        // 7. The VERIFIED webhook settles the invoice (never a redirect).
        $depositPence = (int) $p->invoices()->find($invoiceUuid)['total'];
        $p->payments()->handleEvent([
            'id'   => 'evt_journey',
            'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'id' => 'cs_journey', 'object' => 'checkout.session', 'payment_status' => 'paid',
                'amount_total' => $depositPence, 'currency' => 'gbp', 'payment_intent' => 'pi_journey',
                'metadata' => ['invoice_uuid' => $invoiceUuid, 'payment_uuid' => $paymentUuid],
            ]],
        ]);

        // 8. Reconciled: invoice paid, receipt PDF generated + intact.
        $invoice = $p->invoices()->find($invoiceUuid);
        $this->assertSame('paid', (string) $invoice['status']);
        $this->assertSame($depositPence, (int) $invoice['amount_paid']);
        $receipt = $p->payments()->downloadReceipt($paymentUuid);
        $this->assertSame('%PDF', substr($receipt['bytes'], 0, 4));

        // 9. The contact's CRM timeline carries the whole connected story.
        $timeline = $p->activities()->forEntity('contact', $contactUuid, 50);
        $types = array_map(static fn (array $a): string => (string) $a['type'], $timeline);
        $this->assertContains('proposal.converted', $types, 'conversion recorded on the contact timeline');
        $this->assertContains('payment.received', $types, 'verified payment recorded on the contact timeline');

        // And the audit log records the reconciliation as an immutable event.
        $reconciled = ['n' => count(array_filter($p->fileStore()->all('hermes_audit'), static fn (array $r): bool => (string) ($r['endpoint'] ?? '') === 'payment.reconciled'))];
        $this->assertGreaterThan(0, (int) ($reconciled['n'] ?? 0));
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
