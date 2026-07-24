<?php

declare(strict_types=1);

namespace Breakfast\Tests\Integration;

use Breakfast\Platform\Admin\ApiException;
use Breakfast\Platform\Admin\CrmWrite;
use Breakfast\Platform\Support\Database;
use Breakfast\Platform\Support\Platform;
use Kirby\Cms\App;
use PHPUnit\Framework\TestCase;

/**
 * The CRM write layer that powers "Add a lead / contact / company / task / deal"
 * and timeline notes. Proves every write is REAL and persisted: records land in
 * the database, related entities are linked atomically, activities and audit
 * events are written, duplicates are merged by email, and invalid input is
 * rejected with per-field messages (never a fake success).
 */
final class CrmWriteTest extends TestCase
{
    private App $kirby;
    private string $tmp;
    private CrmWrite $write;

    protected function setUp(): void
    {
        parent::setUp();
        $base = dirname(__DIR__, 2);
        $this->tmp = sys_get_temp_dir() . '/bf-crmwrite-' . bin2hex(random_bytes(6));
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
            'options' => [
                'debug'  => false,
                'whoops' => false,
                'breakfast' => [
                    'production' => false,
                    'storageDir' => $this->tmp,
                    'dbPath'     => $this->tmp . '/database/crm.sqlite',
                    'mail'       => ['provider' => 'fake'],
                ],
            ],
        ]);
        breakfast()->migrator()->migrate();
        $this->write = new CrmWrite(breakfast());
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

    public function testCreateLeadPersistsContactCompanyEnquiryActivityAuditAndFollowUp(): void
    {
        $p = breakfast();
        $result = $this->write->createLead([
            'name'           => 'Sian Roberts',
            'email'          => 'sian@example.co.uk',
            'company'        => 'Roberts Cafe',
            'phone'          => '01492 000000',
            'source'         => 'referral',
            'next_action'    => 'Send proposal',
            'follow_up_date' => '2026-08-01',
        ], 'admin@test');

        $this->assertSame(1, $p->enquiries()->count());
        $this->assertSame(1, $p->contacts()->count());
        $this->assertSame(1, $p->companies()->count());
        $this->assertNotEmpty($result['enquiry']['reference'] ?? null);

        // Follow-up task was created.
        $this->assertCount(1, $p->tasks()->search(['limit' => 10]));

        // Timeline activity + audit event recorded.
        $this->assertNotEmpty($p->activities()->forEntity('contact', (string) $result['contact']['uuid']));
        $this->assertNotEmpty($p->audit()->recent(10));
    }

    public function testCreateLeadDeduplicatesContactByEmail(): void
    {
        $p = breakfast();
        $this->write->createLead(['name' => 'Sian Roberts', 'email' => 'sian@example.co.uk'], 'admin@test');
        $this->write->createLead(['name' => 'Sian Roberts', 'email' => 'sian@example.co.uk'], 'admin@test');

        $this->assertSame(1, $p->contacts()->count(), 'same email must not create a second contact');
        $this->assertSame(2, $p->enquiries()->count(), 'but each enquiry is its own record');
    }

    public function testCreateLeadRejectsEmptyInputWithFieldErrors(): void
    {
        try {
            $this->write->createLead([], 'admin@test');
            $this->fail('Expected a validation exception');
        } catch (ApiException $e) {
            $this->assertSame(422, $e->status);
            $this->assertArrayHasKey('name', $e->fields);
        }
    }

    public function testCreateContactLinksCompany(): void
    {
        $p = breakfast();
        $result = $this->write->createContact([
            'first_name' => 'Dai',
            'last_name'  => 'Evans',
            'email'      => 'dai@evansjoinery.co.uk',
            'company'    => 'Evans Joinery',
            'job_title'  => 'Owner',
        ], 'admin@test');

        $this->assertSame(1, $p->contacts()->count());
        $this->assertSame('Evans Joinery', $result['company']['name'] ?? null);
        $this->assertNotEmpty($result['contact']['company_uuid'] ?? null);
    }

    public function testCreateCompanyRequiresName(): void
    {
        $this->expectException(ApiException::class);
        $this->write->createCompany([], 'admin@test');
    }

    public function testCreateTaskRequiresTitle(): void
    {
        $this->expectException(ApiException::class);
        $this->write->createTask(['title' => ''], 'admin@test');
    }

    public function testCreateOpportunityRejectsUnknownStage(): void
    {
        try {
            $this->write->createOpportunity(['title' => 'X', 'stage' => 'not-a-stage'], 'admin@test');
            $this->fail('Expected a validation exception');
        } catch (ApiException $e) {
            $this->assertArrayHasKey('stage', $e->fields);
        }
    }

    public function testCreateOpportunityPersists(): void
    {
        $opp = $this->write->createOpportunity(['title' => 'Cafe website', 'stage' => 'new', 'value' => 2500], 'admin@test');
        $this->assertSame('Cafe website', $opp['title']);
        $this->assertSame(1, breakfast()->opportunities()->countOpen());
    }

    public function testAddNoteRejectsUnknownEntityType(): void
    {
        $this->expectException(ApiException::class);
        $this->write->addNote('nonsense', 'x', 'hello', 'admin@test');
    }

    // -- Edit / convert / archive ----------------------------------------

    public function testEditContactUpdatesOnlyGivenFieldsAndAudits(): void
    {
        $p = breakfast();
        $lead = $this->write->createLead(['name' => 'Sian', 'email' => 'sian@ex.co'], 'admin@test');
        $uuid = (string) $lead['contact']['uuid'];

        $res = $this->write->editContact($uuid, ['phone' => '01492 111', 'job_title' => 'Owner'], 'admin@test');
        $this->assertSame('01492 111', $res['contact']['phone']);
        $this->assertSame('Owner', $res['contact']['role']);
        // Email is untouched (not provided).
        $this->assertSame('sian@ex.co', $res['contact']['email']);
        $this->assertContains('contact.updated', array_column($p->audit()->recent(10), 'endpoint'));
    }

    public function testEditContactRejectsInvalidEmail(): void
    {
        $lead = $this->write->createLead(['name' => 'Sian', 'email' => 'sian@ex.co'], 'admin@test');
        try {
            $this->write->editContact((string) $lead['contact']['uuid'], ['email' => 'nope'], 'admin@test');
            $this->fail('Expected validation error');
        } catch (ApiException $e) {
            $this->assertSame(422, $e->status);
            $this->assertArrayHasKey('email', $e->fields);
        }
    }

    public function testEditContactOn404(): void
    {
        $this->expectException(ApiException::class);
        $this->write->editContact('does-not-exist', ['phone' => 'x'], 'admin@test');
    }

    public function testEditLeadUpdatesStatus(): void
    {
        $lead = $this->write->createLead(['name' => 'Sian', 'email' => 'sian@ex.co'], 'admin@test');
        $res = $this->write->editLead((string) $lead['enquiry']['uuid'], ['status' => 'qualified'], 'admin@test');
        $this->assertSame('qualified', $res['enquiry']['status']);
    }

    public function testConvertLeadCreatesOpportunityAtomicallyAndMarksConverted(): void
    {
        $p = breakfast();
        $lead = $this->write->createLead(['name' => 'Sian', 'email' => 'sian@ex.co', 'company' => 'Roberts Cafe'], 'admin@test');
        $eid = (string) $lead['enquiry']['uuid'];

        $res = $this->write->convertLead($eid, ['title' => 'Cafe site', 'value' => 250000, 'stage' => 'new'], 'admin@test');
        $this->assertSame('Cafe site', $res['opportunity']['title']);
        $this->assertSame(250000, (int) $res['opportunity']['estimated_value']);
        $this->assertSame('converted', $p->enquiries()->find($eid)['status']);
        $this->assertSame(1, $p->opportunities()->countOpen());
        $this->assertContains('lead.converted', array_column($p->audit()->recent(10), 'endpoint'));
    }

    public function testConvertLeadIsBlockedOnceConverted(): void
    {
        $lead = $this->write->createLead(['name' => 'Sian', 'email' => 'sian@ex.co'], 'admin@test');
        $eid = (string) $lead['enquiry']['uuid'];
        $this->write->convertLead($eid, [], 'admin@test');
        try {
            $this->write->convertLead($eid, [], 'admin@test');
            $this->fail('Expected a conflict');
        } catch (ApiException $e) {
            $this->assertSame(409, $e->status);
        }
    }

    public function testArchiveContactAndLead(): void
    {
        $p = breakfast();
        $lead = $this->write->createLead(['name' => 'Sian', 'email' => 'sian@ex.co'], 'admin@test');
        $cid = (string) $lead['contact']['uuid'];
        $eid = (string) $lead['enquiry']['uuid'];

        $this->write->archiveContact($cid, 'admin@test');
        $this->assertSame('archived', $p->contacts()->find($cid)['status']);

        $this->write->archiveLead($eid, 'admin@test');
        $this->assertSame('archived', $p->enquiries()->find($eid)['status']);
    }

    public function testEditCompanyPersistsFieldsAndAudits(): void
    {
        $p = breakfast();
        $company = $this->write->createCompany(['name' => 'Roberts Cafe'], 'admin@test');
        $uuid = (string) $company['uuid'];

        $res = $this->write->editCompany($uuid, ['name' => 'Roberts Coffee', 'sector' => 'Hospitality', 'website' => 'roberts.example'], 'admin@test');
        $this->assertSame('Roberts Coffee', (string) $res['company']['name']);
        $this->assertSame('Hospitality', (string) $p->companies()->find($uuid)['industry']);
        $this->assertNotEmpty($p->audit()->recent(10));
    }

    public function testArchiveAndRestoreCompanyHidesThenRestoresItWithRelationshipCounts(): void
    {
        $p = breakfast();
        // A company with a linked contact — the archive must report the relationship.
        $lead = $this->write->createLead(['name' => 'Dai Evans', 'email' => 'dai@evans.co', 'company' => 'Evans Joinery'], 'admin@test');
        $uuid = (string) $p->contacts()->find((string) $lead['contact']['uuid'])['company_uuid'];

        $archived = $this->write->archiveCompany($uuid, 'admin@test');
        $this->assertGreaterThanOrEqual(1, (int) $archived['related']['contacts']);
        $this->assertNotNull($p->companies()->find($uuid)['archived_at']);
        $this->assertSame(0, $p->companies()->count(), 'archived companies drop out of the active count');
        $this->assertSame(1, $p->companies()->count(true), 'but remain when archived are included');

        $this->write->restoreCompany($uuid, 'admin@test');
        $this->assertNull($p->companies()->find($uuid)['archived_at']);
        $this->assertSame(1, $p->companies()->count());
    }

    public function testEditOpportunityUpdatesFields(): void
    {
        $opp = $this->write->createOpportunity(['title' => 'Website', 'stage' => 'new', 'value' => 1000], 'admin@test');
        $uuid = (string) $opp['uuid'];

        $res = $this->write->editOpportunity($uuid, ['title' => 'Website + SEO', 'value' => 250000, 'probability' => 60], 'admin@test');
        $this->assertSame('Website + SEO', (string) $res['opportunity']['title']);
        $this->assertSame(250000, (int) $res['opportunity']['estimated_value']);
        $this->assertSame(60, (int) $res['opportunity']['probability']);
    }

    public function testCloseOpportunityWonLostAbandonedAndRestore(): void
    {
        $p = breakfast();

        $won = (string) $this->write->createOpportunity(['title' => 'A', 'stage' => 'proposal'], 'admin@test')['uuid'];
        $this->write->closeOpportunity($won, 'won', [], 'admin@test');
        $this->assertSame('won', (string) $p->opportunities()->find($won)['stage']);
        $this->assertNotEmpty($p->opportunities()->find($won)['won_at']);

        $lost = (string) $this->write->createOpportunity(['title' => 'B', 'stage' => 'proposal'], 'admin@test')['uuid'];
        $this->write->closeOpportunity($lost, 'lost', ['reason' => 'Budget'], 'admin@test');
        $this->assertSame('lost', (string) $p->opportunities()->find($lost)['stage']);
        $this->assertSame('Budget', (string) $p->opportunities()->find($lost)['lost_reason']);

        $abandoned = (string) $this->write->createOpportunity(['title' => 'C', 'stage' => 'discovery'], 'admin@test')['uuid'];
        $this->write->closeOpportunity($abandoned, 'abandoned', [], 'admin@test');
        $this->assertNotNull($p->opportunities()->find($abandoned)['archived_at']);
        $this->assertSame('abandoned', (string) $p->opportunities()->find($abandoned)['close_outcome']);

        // Abandoned/archived opportunities leave the active pipeline but restore.
        $activeIds = array_column($p->opportunities()->search(['limit' => 50]), 'uuid');
        $this->assertNotContains($abandoned, $activeIds);

        $this->write->restoreOpportunity($abandoned, 'admin@test');
        $this->assertNull($p->opportunities()->find($abandoned)['archived_at']);
        $activeIds = array_column($p->opportunities()->search(['limit' => 50]), 'uuid');
        $this->assertContains($abandoned, $activeIds);
    }

    public function testCloseOpportunityRejectsUnknownOutcome(): void
    {
        $uuid = (string) $this->write->createOpportunity(['title' => 'X', 'stage' => 'new'], 'admin@test')['uuid'];
        try {
            $this->write->closeOpportunity($uuid, 'maybe', [], 'admin@test');
            $this->fail('Expected a validation exception');
        } catch (ApiException $e) {
            $this->assertSame(422, $e->status);
        }
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
