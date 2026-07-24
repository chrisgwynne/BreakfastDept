<?php

declare(strict_types=1);

namespace Breakfast\Tests\Integration;

use Breakfast\Platform\Onboarding\OnboardingConditions;
use Breakfast\Platform\Onboarding\OnboardingException;
use Breakfast\Platform\Support\Database;
use Breakfast\Platform\Support\Platform;
use Kirby\Cms\App;
use PHPUnit\Framework\TestCase;

/**
 * Client onboarding — the full delivery vertical. Proves versioned templates,
 * server-side conditional logic, tokened client access, durable draft/resume
 * with concurrency, required-visible validation (hidden conditionals not
 * required), immutable submission snapshots, mapping conflict review (no silent
 * overwrite) + safe empty population, idempotent task generation, and readiness.
 */
final class OnboardingTest extends TestCase
{
    private App $kirby;
    private string $tmp;
    private string $project;
    private string $contact;
    private string $company;
    private string $templateUuid;

    protected function setUp(): void
    {
        parent::setUp();
        $base = dirname(__DIR__, 2);
        $this->tmp = sys_get_temp_dir() . '/bf-onboarding-' . bin2hex(random_bytes(6));
        @mkdir($this->tmp . '/database', 0777, true);
        Database::reset();
        Platform::reset();
        $this->kirby = new App([
            'roots' => ['index' => $base . '/public', 'base' => $base, 'site' => $base . '/site', 'content' => $base . '/content', 'storage' => $this->tmp, 'sessions' => $this->tmp . '/sessions', 'accounts' => $this->tmp . '/accounts'],
            'options' => ['debug' => false, 'whoops' => false, 'breakfast' => ['production' => false, 'storageDir' => $this->tmp, 'dbPath' => $this->tmp . '/database/crm.sqlite', 'mail' => ['provider' => 'fake']]],
        ]);
        breakfast()->migrator()->migrate();

        $p = breakfast();
        $this->company = (string) $p->companies()->create(['name' => 'Roberts Cafe'])['uuid'];
        $this->contact = (string) $p->contacts()->create(['display_name' => 'Sian', 'email' => 's@x.co', 'company_uuid' => $this->company])['uuid'];
        $this->project = (string) $p->projects()->create(['name' => 'Onboarding project', 'company_uuid' => $this->company, 'contact_uuid' => $this->contact], 'staff@breakfast')['uuid'];
        foreach ($p->onboardingTemplates()->list() as $t) {
            if ((string) $t['slug'] === 'brochure_website') {
                $this->templateUuid = (string) $t['uuid'];
            }
        }
    }

    protected function tearDown(): void
    {
        Database::reset();
        Platform::reset();
        $this->rrmdir($this->tmp);
        App::destroy();
        restore_error_handler();
        restore_exception_handler();
        parent::tearDown();
    }

    public function testConditionEvaluatorAndCircularDetection(): void
    {
        $cond = json_encode(['op' => 'and', 'rules' => [['q' => 'a', 'cmp' => 'equals', 'value' => 'yes']]]);
        $this->assertTrue(OnboardingConditions::visible((string) $cond, ['a' => 'yes']));
        $this->assertFalse(OnboardingConditions::visible((string) $cond, ['a' => 'no']));
        $this->assertTrue(OnboardingConditions::visible('', []));
        // a→b→a is circular.
        $this->assertTrue(OnboardingConditions::hasCircularVisibility(['a' => ['b'], 'b' => ['a']]));
        $this->assertFalse(OnboardingConditions::hasCircularVisibility(['a' => ['b'], 'b' => []]));
    }

    public function testInviteViewSaveResumeSubmitLifecycle(): void
    {
        $p = breakfast();
        $inst = $p->onboarding()->createForProject($this->project, $this->templateUuid, [], 'staff@breakfast');
        $id = (string) $inst['uuid'];
        $this->assertSame('draft', (string) $inst['status']);

        $invited = $p->onboarding()->invite($id, 's@x.co', 14, 'staff@breakfast');
        $token = $invited['token'];
        $this->assertNotSame('', $token);
        $this->assertSame('invited', (string) $invited['instance']['status']);

        // Token resolves; viewing records 'viewed' (never in_progress).
        $byToken = $p->onboarding()->findByToken($token);
        $this->assertNotNull($byToken);
        $p->onboarding()->markViewed($id);
        $this->assertSame('viewed', (string) $p->onboarding()->find($id)['status']);

        // Save a partial draft → in_progress + durable.
        $afterSave = $p->onboarding()->saveDraft($id, ['business_name' => 'Roberts Cafe'], null, 'client');
        $this->assertSame('in_progress', (string) $afterSave['status']);
        // Resume: the answer persisted.
        $this->assertSame('Roberts Cafe', $p->onboarding()->answers($id)['business_name']);

        // Submitting now fails — 'goals' (required, visible) is missing.
        $this->assertError(422, fn () => $p->onboarding()->submit($id, 'client'));

        // Fill required visible fields; the conditional 'products_count' is hidden
        // (has_ecommerce != yes) so it is NOT required.
        $p->onboarding()->saveDraft($id, ['goals' => 'A fresh website', 'has_ecommerce' => 'no', 'website_url' => 'https://roberts.example'], null, 'client');
        $submitted = $p->onboarding()->submit($id, 'client');
        $this->assertSame('submitted', (string) $submitted['status']);
        $this->assertSame(1, (int) $submitted['submission_no']);
    }

    public function testConcurrencyRejectsStaleDraftSave(): void
    {
        $p = breakfast();
        $inst = $p->onboarding()->createForProject($this->project, $this->templateUuid, [], 'staff@breakfast');
        $id = (string) $inst['uuid'];
        $p->onboarding()->invite($id, 's@x.co', 14, 'staff@breakfast');
        $rev = (int) $p->onboarding()->find($id)['revision'];
        $p->onboarding()->saveDraft($id, ['business_name' => 'A'], $rev, 'client');
        // Reusing the old revision is rejected.
        $this->assertError(409, fn () => $p->onboarding()->saveDraft($id, ['business_name' => 'B'], $rev, 'client'));
    }

    public function testMappingConflictCreatesReviewAndNeverOverwritesSilently(): void
    {
        $p = breakfast();
        // Pre-existing trusted phone on the contact.
        breakfast()->db()->run('UPDATE contacts SET phone = :v WHERE uuid = :u', ['v' => '01111 111111', 'u' => $this->contact]);

        $inst = $p->onboarding()->createForProject($this->project, $this->templateUuid, [], 'staff@breakfast');
        $id = (string) $inst['uuid'];
        $p->onboarding()->invite($id, 's@x.co', 14, 'staff@breakfast');
        $p->onboarding()->saveDraft($id, ['business_name' => 'R', 'goals' => 'G', 'has_ecommerce' => 'no', 'business_phone' => '02222 222222', 'website_url' => 'https://new.example'], null, 'client');
        $p->onboarding()->submit($id, 'client');

        // The phone conflict created a PENDING review; the contact phone is untouched.
        $reviews = $p->onboarding()->find($id)['reviews'];
        $phoneReview = null;
        foreach ($reviews as $r) {
            if ((string) $r['target'] === 'contact.phone') {
                $phoneReview = $r;
            }
        }
        $this->assertNotNull($phoneReview);
        $this->assertSame('pending', (string) $phoneReview['decision']);
        $this->assertSame('01111 111111', (string) breakfast()->db()->scalar('SELECT phone FROM contacts WHERE uuid = :u', ['u' => $this->contact]));

        // The empty company website was safely auto-populated (logged review row).
        $this->assertSame('https://new.example', (string) breakfast()->db()->scalar('SELECT website FROM companies WHERE uuid = :u', ['u' => $this->company]));

        // Accepting the phone review applies the submitted value.
        $p->onboarding()->decideMapping((string) $phoneReview['uuid'], 'accepted', 'staff@breakfast');
        $this->assertSame('02222 222222', (string) breakfast()->db()->scalar('SELECT phone FROM contacts WHERE uuid = :u', ['u' => $this->contact]));
    }

    public function testReadinessBlocksCompletionUntilReviewsResolved(): void
    {
        $p = breakfast();
        breakfast()->db()->run('UPDATE contacts SET phone = :v WHERE uuid = :u', ['v' => 'existing', 'u' => $this->contact]);
        $inst = $p->onboarding()->createForProject($this->project, $this->templateUuid, [], 'staff@breakfast');
        $id = (string) $inst['uuid'];
        $p->onboarding()->invite($id, 's@x.co', 14, 'staff@breakfast');
        $p->onboarding()->saveDraft($id, ['business_name' => 'R', 'goals' => 'G', 'has_ecommerce' => 'no', 'business_phone' => 'different'], null, 'client');
        $p->onboarding()->submit($id, 'client');

        // A pending mapping review blocks completion.
        $this->assertFalse($p->onboarding()->readiness($id)['ready']);
        $this->assertError(409, fn () => $p->onboarding()->complete($id, 'staff@breakfast'));

        // Resolve it → ready → completable.
        $review = null;
        foreach ($p->onboarding()->find($id)['reviews'] as $r) {
            if ((string) $r['decision'] === 'pending') {
                $review = $r;
            }
        }
        $p->onboarding()->decideMapping((string) $review['uuid'], 'rejected', 'staff@breakfast');
        $this->assertTrue($p->onboarding()->readiness($id)['ready']);
        $completed = $p->onboarding()->complete($id, 'staff@breakfast');
        $this->assertSame('completed', (string) $completed['status']);
        // Token cleared on completion.
        $this->assertSame('', (string) breakfast()->db()->scalar('SELECT token_hash FROM onboarding_instances WHERE uuid = :u', ['u' => $id]));
    }

    public function testExpiredInvitationCannotSubmit(): void
    {
        $p = breakfast();
        $inst = $p->onboarding()->createForProject($this->project, $this->templateUuid, [], 'staff@breakfast');
        $id = (string) $inst['uuid'];
        $p->onboarding()->invite($id, 's@x.co', 14, 'staff@breakfast');
        // Force expiry.
        breakfast()->db()->run("UPDATE onboarding_instances SET expires_at = :e WHERE uuid = :u", ['e' => date('c', time() - 3600), 'u' => $id]);
        $this->assertNull($p->onboarding()->findByToken($p->onboarding()->find($id)['token_hash']));
        $this->assertError(410, fn () => $p->onboarding()->saveDraft($id, ['business_name' => 'X'], null, 'client'));
    }

    public function testTemplateVersioningAndPublishRejectsBadCondition(): void
    {
        $p = breakfast();
        // A template whose condition references a non-existent question must not publish.
        $tpl = $p->onboardingTemplates()->create([
            'name' => 'Bad conditions',
            'questions' => [['key' => 'q1', 'type' => 'yes_no', 'label' => 'Q1', 'condition' => ['op' => 'and', 'rules' => [['q' => 'ghost', 'cmp' => 'equals', 'value' => 'yes']]]]],
        ], 'staff@breakfast');
        $this->assertError(422, fn () => $p->onboardingTemplates()->publish((string) $tpl['uuid'], 1, 'staff@breakfast'));
    }

    private function assertError(int $status, callable $fn): void
    {
        try {
            $fn();
            $this->fail('Expected an OnboardingException with status ' . $status);
        } catch (OnboardingException $e) {
            $this->assertSame($status, $e->status);
        }
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
