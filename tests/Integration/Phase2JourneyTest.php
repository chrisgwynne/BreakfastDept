<?php

declare(strict_types=1);

namespace Breakfast\Tests\Integration;

use Breakfast\Platform\Support\Platform;
use Kirby\Cms\App;
use PHPUnit\Framework\TestCase;

/**
 * Phase 2 integrated end-to-end acceptance journey (Delivery).
 *
 * One connected delivery lifecycle exercising every Phase 2 vertical with no
 * shortcuts: an accepted proposal becomes a project → a template generates real
 * milestones + tasks → the client completes onboarding (evidenced, mapped) → a
 * client file is stored (versioned, integrity-checked) → a credential is vaulted
 * (encrypted, revealed only after step-up) → a priced change request is sent,
 * approved by the client (evidenced) and applied — adding approved value to the
 * PROJECT, generating a task and drafting an invoice whose total matches the
 * approved change — and the whole story lands on the CRM timeline and audit log.
 *
 * This is the authoritative, deterministic proof that the delivery verticals
 * connect into a single workflow rather than standing alone.
 */
final class Phase2JourneyTest extends TestCase
{
    private App $kirby;
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $base = dirname(__DIR__, 2);
        $this->tmp = sys_get_temp_dir() . '/bf-p2-journey-' . bin2hex(random_bytes(6));

        Platform::reset();
        putenv('PLATFORM_SECRET_KEY=' . base64_encode(random_bytes(32)));

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
        Platform::reset();
        putenv('PLATFORM_SECRET_KEY');
        $this->rrmdir($this->tmp);
        App::destroy();
        restore_error_handler();
        restore_exception_handler();
        parent::tearDown();
    }

    public function testAcceptedProposalThroughDeliveryToAppliedChange(): void
    {
        $p = breakfast();
        $actor = 'chris@breakfast';

        // 0. CRM baseline: a company + contact for the client.
        $company = (string) $p->companies()->create(['name' => 'Roberts Cafe'])['uuid'];
        $contact = (string) $p->contacts()->create(['display_name' => 'Sian Roberts', 'email' => 'sian@roberts.example', 'company_uuid' => $company])['uuid'];

        // 1. An accepted proposal for £3,000 (the Phase 1 commercial baseline).
        $proposal = $p->proposals()->create([
            'title' => 'Roberts Cafe website', 'client_name' => 'Sian Roberts', 'client_email' => 'sian@roberts.example',
            'contact_uuid' => $contact, 'company_uuid' => $company,
            'items' => [['kind' => 'fixed', 'description' => 'Design & build', 'quantity' => 1, 'unit_price' => 3000, 'tax_rate' => 0]],
        ], $actor);
        $proposalUuid = (string) $proposal['uuid'];
        $p->proposals()->send($proposalUuid, $actor, ['company_legal_name' => 'Breakfast Ltd', 'proposal_prefix' => 'PRO']);
        $p->proposals()->accept($proposalUuid, ['name' => 'Sian Roberts', 'email' => 'sian@roberts.example', 'ip_hash' => 'h', 'user_agent' => 'UA']);

        // 2. Start the delivery project FROM the accepted proposal (by reference —
        //    the frozen commercial terms are never copied into mutable fields).
        $project = $p->projectConversion()->fromProposal($proposalUuid, [], $actor);
        $projectUuid = (string) $project['uuid'];
        $this->assertSame(300000, (int) $project['quoted_value']);
        $this->assertSame($proposalUuid, (string) $project['proposal_uuid']);

        // 3. A published template generates real milestones + business-day-dated tasks.
        $template = null;
        foreach ($p->projectTemplates()->list() as $t) {
            if ((int) $t['current_version'] > 0) {
                $template = $t;
                break;
            }
        }
        $this->assertNotNull($template, 'a published project template exists');
        $applied = $p->projectTemplates()->applyToProject($projectUuid, (string) $template['uuid'], $actor, '2026-08-03');
        $this->assertGreaterThan(0, (int) $applied['milestones']);
        $this->assertGreaterThan(0, (int) $applied['tasks']);
        $baselineTasks = count($p->projectTasks()->forProject($projectUuid));

        // 4. Client onboarding: create → invite → client fills required fields →
        //    submit (immutable snapshot) → any mapping conflicts decided → complete.
        $tpl = '';
        foreach ($p->onboardingTemplates()->list() as $ot) {
            if ((string) $ot['slug'] === 'brochure_website') {
                $tpl = (string) $ot['uuid'];
            }
        }
        $this->assertNotSame('', $tpl);
        $inst = (string) $p->onboarding()->createForProject($projectUuid, $tpl, [], $actor)['uuid'];
        $p->onboarding()->invite($inst, 'sian@roberts.example', 14, $actor);
        $p->onboarding()->saveDraft($inst, ['business_name' => 'Roberts Cafe', 'goals' => 'A fresh website', 'has_ecommerce' => 'no', 'website_url' => 'https://roberts.example'], null, 'client');
        $submitted = $p->onboarding()->submit($inst, 'client');
        $this->assertSame('submitted', (string) $submitted['status']);
        foreach ($p->onboarding()->find($inst)['reviews'] as $review) {
            if ((string) $review['decision'] === 'pending') {
                $p->onboarding()->decideMapping((string) $review['uuid'], 'accepted', $actor);
            }
        }
        $completed = $p->onboarding()->complete($inst, $actor);
        $this->assertSame('completed', (string) $completed['status']);

        // 5. A client file is stored (real bytes, versioned, integrity-checked).
        $tmpFile = $this->tmp . '/brand.png';
        file_put_contents($tmpFile, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));
        $file = $p->files()->upload($tmpFile, 'brand.png', 'image/png', ['project_uuid' => $projectUuid, 'entity_type' => 'project', 'entity_uuid' => $projectUuid, 'display_name' => 'Brand mark'], $actor);
        $download = $p->files()->download((string) $file['uuid'], null, $actor);
        $this->assertSame(1, (int) $file['current_version']);
        $this->assertSame("\x89PNG", substr($download['bytes'], 0, 4));

        // 6. A credential is vaulted (encrypted at rest; masked; revealed only after
        //    step-up re-authentication).
        $canary = 'HOSTING-PASS-7Q2z9';
        $item = $p->vault()->create(['label' => 'Hosting login', 'project_uuid' => $projectUuid, 'fields' => [['fkey' => 'password', 'label' => 'Password', 'value' => $canary]]], $actor);
        $vaultUuid = (string) $item['id'];
        // The plaintext is never in any stored record (encrypted at rest).
        $dbDump = '';
        foreach (glob($this->tmp . '/data/*/*.json') ?: [] as $f) {
            $dbDump .= (string) file_get_contents($f);
        }
        $this->assertStringNotContainsString($canary, $dbDump, 'secret is encrypted at rest');
        $p->vault()->grantReauth($actor);
        $this->assertSame($canary, $p->vault()->reveal($vaultUuid, 'password', $actor));

        // 7. A priced change request: create → send (freeze snapshot + real PDF +
        //    token) → client approves (evidenced) → apply (adds value to the
        //    project, generates a task, drafts a matching invoice).
        $cr = $p->changeRequests()->create($projectUuid, [
            'title' => 'Add an online booking widget',
            'items' => [['description' => 'Booking integration', 'quantity' => 1, 'unit_price' => 600, 'kind' => 'fixed', 'estimate_hours' => 5]],
        ], $actor);
        $crUuid = (string) $cr['uuid'];
        $sent = $p->changeRequests()->send($crUuid, 'https://breakfast.test', $actor);
        $this->assertSame('generated', (string) $sent['change_request']['document_status']);
        $approved = $p->changeRequests()->decide($crUuid, 'approved', ['name' => 'Sian Roberts', 'email' => 'sian@roberts.example'], 'client');
        $approvedTotal = (int) $approved['total'];
        $this->assertSame(60000, $approvedTotal);

        $result = $p->changeRequests()->apply($crUuid, [], $actor);
        $this->assertTrue($result['applied']);
        $this->assertSame(1, (int) $result['tasks']);
        $this->assertNotNull($result['invoice']);

        // The value lands on the PROJECT (variations), never the frozen proposal.
        $finalProject = $p->projects()->find($projectUuid);
        $this->assertSame($approvedTotal, (int) $finalProject['approved_variations']);
        $this->assertSame(300000, (int) $finalProject['quoted_value']);
        $this->assertSame(300000, (int) $p->proposals()->find($proposalUuid)['total'], 'the accepted proposal is unchanged');

        // The change generated exactly one new delivery task, and drafted an
        // invoice whose total matches the approved change to the penny.
        $this->assertSame($baselineTasks + 1, count($p->projectTasks()->forProject($projectUuid)));
        $invoice = $p->invoices()->find((string) $result['invoice']);
        $this->assertSame('draft', (string) $invoice['status']);
        $this->assertSame($approvedTotal, (int) $invoice['total']);

        // 8. The connected story is on the contact's CRM timeline + audit log.
        $types = array_map(static fn (array $a): string => (string) $a['type'], $p->activities()->forEntity('contact', $contact, 100));
        $this->assertContains('change_request.approved', $types, 'client approval recorded on the timeline');
        $auditApplied = count(array_filter($p->fileStore()->all('hermes_audit'), static fn (array $r): bool => (string) ($r['endpoint'] ?? '') === 'change_request.applied'));
        $this->assertGreaterThan(0, $auditApplied, 'applying the change is an immutable audit event');
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
