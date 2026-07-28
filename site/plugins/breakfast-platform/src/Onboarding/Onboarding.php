<?php

declare(strict_types=1);

namespace Breakfast\Platform\Onboarding;

use Breakfast\Platform\Support\Clock;
use Breakfast\Platform\Support\Database;
use Breakfast\Platform\Support\Platform;
use Breakfast\Platform\Support\Uuid;

/**
 * Onboarding instances — the client-facing delivery vertical.
 *
 * An instance binds to an exact frozen template version. Answers save
 * incrementally (durable, server-authoritative) with optimistic concurrency;
 * submission validates required VISIBLE questions server-side (hidden
 * conditional questions are never required) and snapshots an immutable answer
 * version. Selected answers PROPOSE field updates: when they conflict with
 * trusted existing data a review item is created — data is never silently
 * overwritten. Generated tasks are idempotent across resubmission.
 */
final class Onboarding
{
    /** Mappable targets → [table, column]. Everything else routes via mode. */
    private const TARGETS = [
        'contact.phone'        => ['contacts', 'phone'],
        'company.website'      => ['companies', 'website'],
        'project.client_summary' => ['projects', 'client_summary'],
        'project.scope'        => ['projects', 'scope'],
    ];

    public function __construct(
        private readonly Database $db,
        private readonly Platform $platform,
    ) {
    }

    private function templates(): OnboardingTemplates
    {
        return new OnboardingTemplates($this->db);
    }

    // ==================================================================
    // Read
    // ==================================================================

    /** @return list<array<string,mixed>> */
    public function forProject(string $projectUuid): array
    {
        return $this->db->all('SELECT * FROM onboarding_instances WHERE project_uuid = :p ORDER BY created_at DESC', ['p' => $projectUuid]);
    }

    /** @return array<string,mixed>|null */
    public function find(string $uuid): ?array
    {
        $i = $this->db->one('SELECT * FROM onboarding_instances WHERE uuid = :u', ['u' => $uuid]);
        if ($i === null) {
            return null;
        }
        $i['answers']    = $this->answers($uuid);
        $i['reviews']    = $this->db->all('SELECT * FROM onboarding_mapping_reviews WHERE instance_uuid = :u ORDER BY created_at ASC', ['u' => $uuid]);
        $i['events']     = $this->db->all('SELECT * FROM onboarding_events WHERE instance_uuid = :u ORDER BY created_at DESC LIMIT 100', ['u' => $uuid]);
        $i['readiness']  = $this->readiness($uuid);

        return $i;
    }

    /** @return array<string,mixed> qkey => value */
    public function answers(string $instanceUuid): array
    {
        $out = [];
        foreach ($this->db->all('SELECT question_key, value FROM onboarding_answers WHERE instance_uuid = :u', ['u' => $instanceUuid]) as $row) {
            $decoded = json_decode((string) $row['value'], true);
            $out[(string) $row['question_key']] = $decoded !== null && (is_array($decoded)) ? $decoded : (string) $row['value'];
        }

        return $out;
    }

    // ==================================================================
    // Create + invite
    // ==================================================================

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function createForProject(string $projectUuid, string $templateUuid, array $data, string $actor): array
    {
        $project = $this->platform->projects()->raw($projectUuid);
        if ($project === null) {
            throw new OnboardingException(404, 'Project not found.');
        }
        $structure = $this->templates()->publishedStructure($templateUuid);
        if ($structure === null) {
            throw new OnboardingException(409, 'That onboarding template has no published version.');
        }
        $uuid = Uuid::v4();
        $now = Clock::nowIso();
        $this->db->run(
            'INSERT INTO onboarding_instances (uuid, template_uuid, version, version_uuid, project_uuid, company_uuid, contact_uuid, opportunity_uuid, proposal_uuid, contract_uuid, status, created_by, created_at, updated_at)
             VALUES (:uuid, :t, :v, :vu, :p, :company, :contact, :opp, :proposal, :contract, \'draft\', :actor, :now, :now)',
            [
                'uuid' => $uuid, 't' => $templateUuid, 'v' => (int) $structure['version'], 'vu' => (string) $structure['uuid'], 'p' => $projectUuid,
                'company' => $this->nullable($project['company_uuid'] ?? null), 'contact' => $this->nullable($project['contact_uuid'] ?? null),
                'opp' => $this->nullable($project['opportunity_uuid'] ?? null), 'proposal' => $this->nullable($project['proposal_uuid'] ?? null), 'contract' => $this->nullable($project['contract_uuid'] ?? null),
                'actor' => $actor, 'now' => $now,
            ]
        );
        $this->event($uuid, 'created', 'Onboarding created from template', $actor);

        return $this->find($uuid) ?? [];
    }

    /**
     * Invite the client. Mints a raw token (returned ONCE) and stores only its
     * hash. Idempotent per call in the sense that re-inviting rotates the token.
     *
     * @return array{instance:array<string,mixed>,token:string}
     */
    public function invite(string $instanceUuid, string $email, int $expiresDays, string $actor): array
    {
        $i = $this->db->one('SELECT status FROM onboarding_instances WHERE uuid = :u', ['u' => $instanceUuid]);
        if ($i === null) {
            throw new OnboardingException(404, 'Onboarding not found.');
        }
        if (in_array((string) $i['status'], ['completed', 'withdrawn', 'cancelled', 'superseded', 'expired'], true)) {
            throw new OnboardingException(409, 'This onboarding can no longer be sent.');
        }
        $raw = bin2hex(random_bytes(24));
        $hash = hash('sha256', $raw);
        $expires = $expiresDays > 0 ? date('c', time() + $expiresDays * 86400) : null;
        $now = Clock::nowIso();
        $this->db->transaction(function (Database $db) use ($instanceUuid, $hash, $expires, $email, $now): void {
            $db->run('UPDATE onboarding_instances SET token_hash = :h, expires_at = :exp, status = \'invited\', invited_at = COALESCE(invited_at, :now), updated_at = :now, revision = revision + 1 WHERE uuid = :u', ['h' => $hash, 'exp' => $expires, 'now' => $now, 'u' => $instanceUuid]);
            // Revoke prior invitations, insert the new one.
            $db->run('UPDATE onboarding_invitations SET revoked = 1 WHERE instance_uuid = :u AND revoked = 0', ['u' => $instanceUuid]);
            $db->run('INSERT INTO onboarding_invitations (uuid, instance_uuid, token_hash, email, expires_at, sent_at, created_at) VALUES (:uuid, :i, :h, :email, :exp, :now, :now)', ['uuid' => Uuid::v4(), 'i' => $instanceUuid, 'h' => $hash, 'email' => $email, 'exp' => $expires, 'now' => $now]);
        });
        $this->event($instanceUuid, 'invited', 'Invitation sent to ' . $email, $actor);

        return ['instance' => $this->find($instanceUuid) ?? [], 'token' => $raw];
    }

    /** @return array<string,mixed> */
    public function withdraw(string $instanceUuid, string $actor): array
    {
        $this->db->run("UPDATE onboarding_instances SET status = 'withdrawn', token_hash = '', updated_at = :now, revision = revision + 1 WHERE uuid = :u", ['now' => Clock::nowIso(), 'u' => $instanceUuid]);
        $this->db->run('UPDATE onboarding_invitations SET revoked = 1 WHERE instance_uuid = :u', ['u' => $instanceUuid]);
        $this->event($instanceUuid, 'withdrawn', 'Onboarding withdrawn', $actor);

        return $this->find($instanceUuid) ?? [];
    }

    // ==================================================================
    // Client (token) side
    // ==================================================================

    /** @return array<string,mixed>|null resolves a live invitation token to its instance */
    public function findByToken(string $rawToken): ?array
    {
        if (trim($rawToken) === '') {
            return null;
        }
        $hash = hash('sha256', $rawToken);
        $i = $this->db->one('SELECT * FROM onboarding_instances WHERE token_hash = :h', ['h' => $hash]);
        if ($i === null) {
            return null;
        }
        if ($this->isExpired($i)) {
            return null;
        }

        return $i;
    }

    /** Viewing records 'viewed' — never 'in_progress'. */
    public function markViewed(string $instanceUuid): void
    {
        $this->db->run("UPDATE onboarding_instances SET status = CASE WHEN status = 'invited' THEN 'viewed' ELSE status END, viewed_at = COALESCE(viewed_at, :now), updated_at = :now WHERE uuid = :u", ['now' => Clock::nowIso(), 'u' => $instanceUuid]);
    }

    /**
     * Durable incremental save. Optimistic concurrency via the instance revision.
     * Moves to in_progress only on meaningful input.
     *
     * @param array<string,mixed> $answers qkey => value
     * @return array<string,mixed>
     */
    public function saveDraft(string $instanceUuid, array $answers, ?int $expectedRevision, string $actor): array
    {
        $i = $this->db->one('SELECT * FROM onboarding_instances WHERE uuid = :u', ['u' => $instanceUuid]);
        if ($i === null) {
            throw new OnboardingException(404, 'Onboarding not found.');
        }
        if ($this->isExpired($i)) {
            throw new OnboardingException(410, 'This onboarding invitation has expired.');
        }
        if (!in_array((string) $i['status'], ['invited', 'viewed', 'in_progress', 'needs_clarification'], true)) {
            throw new OnboardingException(409, 'This onboarding can no longer be edited.');
        }
        if ($expectedRevision !== null && (int) $i['revision'] !== $expectedRevision) {
            throw new OnboardingException(409, 'This form was updated in another window. Reload to continue.');
        }
        $structure = $this->templates()->versionStructure((string) $i['template_uuid'], (int) $i['version']);
        $validKeys = array_map(static fn (array $q): string => (string) $q['qkey'], $structure['questions'] ?? []);
        $now = Clock::nowIso();
        $currentStatus = (string) $i['status'];
        $meaningful = false;
        $this->db->transaction(function (Database $db) use ($instanceUuid, $answers, $validKeys, $now, $currentStatus, &$meaningful): void {
            foreach ($answers as $key => $value) {
                if (!in_array((string) $key, $validKeys, true)) {
                    continue; // ignore answers to unknown/frozen-removed questions
                }
                $stored = is_array($value) ? (json_encode(array_values($value)) ?: '') : (string) $value;
                if (trim($stored) !== '' && $stored !== '[]') {
                    $meaningful = true;
                }
                $db->run(
                    'INSERT INTO onboarding_answers (uuid, instance_uuid, question_key, value, updated_at)
                     VALUES (:uuid, :i, :qk, :val, :now)
                     ON CONFLICT (instance_uuid, question_key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at',
                    ['uuid' => Uuid::v4(), 'i' => $instanceUuid, 'qk' => (string) $key, 'val' => $stored, 'now' => $now]
                );
            }
            // Meaningful client input advances invited/viewed → in_progress.
            $newStatus = ($meaningful && in_array($currentStatus, ['invited', 'viewed'], true)) ? 'in_progress' : $currentStatus;
            $db->run('UPDATE onboarding_instances SET status = :status, updated_at = :now, revision = revision + 1 WHERE uuid = :u', ['status' => $newStatus, 'now' => $now, 'u' => $instanceUuid]);
        });

        return $this->find($instanceUuid) ?? [];
    }

    /**
     * Submit — validates required VISIBLE questions server-side, snapshots the
     * answers as an immutable version, and proposes field mappings.
     *
     * @return array<string,mixed>
     */
    public function submit(string $instanceUuid, string $actor): array
    {
        $i = $this->db->one('SELECT * FROM onboarding_instances WHERE uuid = :u', ['u' => $instanceUuid]);
        if ($i === null) {
            throw new OnboardingException(404, 'Onboarding not found.');
        }
        if ($this->isExpired($i)) {
            throw new OnboardingException(410, 'This onboarding invitation has expired.');
        }
        if (!in_array((string) $i['status'], ['invited', 'viewed', 'in_progress', 'needs_clarification'], true)) {
            throw new OnboardingException(409, 'This onboarding has already been submitted.');
        }
        $structure = $this->templates()->versionStructure((string) $i['template_uuid'], (int) $i['version']);
        $answers = $this->answers($instanceUuid);
        $errors = $this->validate($structure, $answers);
        if ($errors !== []) {
            throw new OnboardingException(422, 'Please complete the required fields: ' . implode(', ', array_slice($errors, 0, 5)));
        }

        $now = Clock::nowIso();
        $result = $this->db->transaction(function (Database $db) use ($instanceUuid, $answers, $now): array {
            $no = (int) $db->scalar('SELECT submission_no FROM onboarding_instances WHERE uuid = :u', ['u' => $instanceUuid]) + 1;
            $db->run('INSERT INTO onboarding_answer_versions (uuid, instance_uuid, submission_no, snapshot, created_at) VALUES (:uuid, :i, :no, :snap, :now)', ['uuid' => Uuid::v4(), 'i' => $instanceUuid, 'no' => $no, 'snap' => json_encode($answers) ?: '{}', 'now' => $now]);
            $db->run("UPDATE onboarding_instances SET status = 'submitted', submitted_at = :now, submission_no = :no, updated_at = :now, revision = revision + 1 WHERE uuid = :u", ['now' => $now, 'no' => $no, 'u' => $instanceUuid]);

            return ['submission_no' => $no];
        });
        $this->event($instanceUuid, 'submitted', 'Submission #' . $result['submission_no'], $actor);

        // Propose mappings (creates review items for conflicts; auto-applies safe empties).
        $this->proposeMappings($instanceUuid, $structure, $answers, $actor);
        $this->generateTasks($instanceUuid, $structure, $actor);

        // CRM activity on the contact.
        $contact = (string) ($i['contact_uuid'] ?? '');
        if ($contact !== '') {
            $this->platform->activities()->record('contact', $contact, 'onboarding.submitted', 'Onboarding submitted', 'client', null, ['instance' => $instanceUuid]);
        }
        $this->platform->audit()->event('onboarding.submitted', 'project', (string) ($i['project_uuid'] ?? ''), $actor, ['instance' => $instanceUuid]);

        return $this->find($instanceUuid) ?? [];
    }

    // ==================================================================
    // Staff review
    // ==================================================================

    /** @return array<string,mixed> */
    public function requestClarification(string $instanceUuid, string $note, string $actor): array
    {
        $i = $this->requireStatus($instanceUuid, ['submitted', 'under_review']);
        $this->db->run("UPDATE onboarding_instances SET status = 'needs_clarification', updated_at = :now, revision = revision + 1 WHERE uuid = :u", ['now' => Clock::nowIso(), 'u' => (string) $i['uuid']]);
        $this->event($instanceUuid, 'clarification_requested', $note, $actor);

        return $this->find($instanceUuid) ?? [];
    }

    /** @return array<string,mixed> */
    public function startReview(string $instanceUuid, string $actor): array
    {
        $i = $this->requireStatus($instanceUuid, ['submitted']);
        $this->db->run("UPDATE onboarding_instances SET status = 'under_review', updated_at = :now, revision = revision + 1 WHERE uuid = :u", ['now' => Clock::nowIso(), 'u' => (string) $i['uuid']]);
        $this->event($instanceUuid, 'review_started', 'Review started', $actor);

        return $this->find($instanceUuid) ?? [];
    }

    /** @return array<string,mixed> */
    public function complete(string $instanceUuid, string $actor): array
    {
        $i = $this->requireStatus($instanceUuid, ['submitted', 'under_review']);
        $readiness = $this->readiness((string) $i['uuid']);
        if (!$readiness['ready']) {
            throw new OnboardingException(409, 'Onboarding is not ready: ' . implode('; ', $readiness['blockers']));
        }
        $this->db->run("UPDATE onboarding_instances SET status = 'completed', completed_at = :now, token_hash = '', updated_at = :now, revision = revision + 1 WHERE uuid = :u", ['now' => Clock::nowIso(), 'u' => (string) $i['uuid']]);
        $this->db->run('UPDATE onboarding_invitations SET revoked = 1 WHERE instance_uuid = :u', ['u' => (string) $i['uuid']]);
        $this->event($instanceUuid, 'completed', 'Onboarding completed', $actor);
        $this->platform->audit()->event('onboarding.completed', 'project', (string) ($i['project_uuid'] ?? ''), $actor, ['instance' => $instanceUuid]);

        return $this->find($instanceUuid) ?? [];
    }

    // ==================================================================
    // Mapping review
    // ==================================================================

    /** @return array<string,mixed> */
    public function decideMapping(string $reviewUuid, string $decision, string $actor): array
    {
        if (!in_array($decision, ['accepted', 'rejected', 'merged'], true)) {
            throw new OnboardingException(422, 'Unknown mapping decision.');
        }
        $review = $this->db->one('SELECT * FROM onboarding_mapping_reviews WHERE uuid = :u', ['u' => $reviewUuid]);
        if ($review === null) {
            throw new OnboardingException(404, 'Mapping review not found.');
        }
        if ((string) $review['decision'] !== 'pending') {
            throw new OnboardingException(409, 'This mapping has already been decided.');
        }
        if ($decision !== 'rejected') {
            $value = $decision === 'merged'
                ? trim((string) $review['existing_value'] . "\n" . (string) $review['submitted_value'])
                : (string) $review['submitted_value'];
            $this->applyTarget((string) $review['target'], (string) $review['instance_uuid'], $value);
        }
        $this->db->run('UPDATE onboarding_mapping_reviews SET decision = :d, reviewer = :r, decided_at = :now WHERE uuid = :u', ['d' => $decision, 'r' => $actor, 'now' => Clock::nowIso(), 'u' => $reviewUuid]);
        $this->event((string) $review['instance_uuid'], 'mapping_' . $decision, (string) $review['target'], $actor);
        $this->platform->audit()->event('onboarding.mapping_' . $decision, 'project', $this->projectOf((string) $review['instance_uuid']), $actor, ['target' => $review['target']]);

        return $this->find((string) $review['instance_uuid']) ?? [];
    }

    // ==================================================================
    // Readiness
    // ==================================================================

    /** @return array{ready:bool,blockers:list<string>,percent:int} */
    public function readiness(string $instanceUuid): array
    {
        $i = $this->db->one('SELECT * FROM onboarding_instances WHERE uuid = :u', ['u' => $instanceUuid]);
        if ($i === null) {
            return ['ready' => false, 'blockers' => ['Onboarding not found'], 'percent' => 0];
        }
        $blockers = [];
        if (!in_array((string) $i['status'], ['submitted', 'under_review', 'completed'], true)) {
            $blockers[] = 'Not yet submitted';
        }
        $pendingReviews = (int) $this->db->scalar("SELECT COUNT(*) FROM onboarding_mapping_reviews WHERE instance_uuid = :u AND decision = 'pending'", ['u' => $instanceUuid]);
        if ($pendingReviews > 0) {
            $blockers[] = $pendingReviews . ' mapping conflict(s) to review';
        }
        if ((string) $i['status'] === 'needs_clarification') {
            $blockers[] = 'Awaiting client clarification';
        }
        // Percent: required visible answered / required visible total.
        $structure = $this->templates()->versionStructure((string) $i['template_uuid'], (int) $i['version']);
        $answers = $this->answers($instanceUuid);
        $reqTotal = 0;
        $reqDone = 0;
        foreach ($structure['questions'] ?? [] as $q) {
            if ((int) $q['required'] !== 1 || in_array((string) $q['type'], ['info', 'heading'], true)) {
                continue;
            }
            if (!OnboardingConditions::visible((string) $q['condition'], $answers)) {
                continue;
            }
            $reqTotal++;
            if (!$this->isEmpty($answers[(string) $q['qkey']] ?? null)) {
                $reqDone++;
            }
        }
        $percent = $reqTotal > 0 ? (int) round($reqDone / $reqTotal * 100) : 100;

        return ['ready' => $blockers === [], 'blockers' => $blockers, 'percent' => $percent];
    }

    // ==================================================================
    // Internals
    // ==================================================================

    /**
     * @param array<string,mixed> $structure
     * @param array<string,mixed> $answers
     * @return list<string> labels of missing required visible questions
     */
    private function validate(array $structure, array $answers): array
    {
        $errors = [];
        foreach ($structure['questions'] ?? [] as $q) {
            if (in_array((string) $q['type'], ['info', 'heading'], true) || (int) $q['internal_only'] === 1) {
                continue;
            }
            // Hidden conditional questions are never required.
            if (!OnboardingConditions::visible((string) $q['condition'], $answers)) {
                continue;
            }
            $value = $answers[(string) $q['qkey']] ?? null;
            if ((int) $q['required'] === 1 && $this->isEmpty($value)) {
                $errors[] = (string) $q['label'];
                continue;
            }
            if (!$this->isEmpty($value) && !$this->validType((string) $q['type'], $value, $q)) {
                $errors[] = (string) $q['label'] . ' (invalid)';
            }
        }

        return $errors;
    }

    /** @param array<string,mixed> $q */
    private function validType(string $type, mixed $value, array $q): bool
    {
        $s = is_array($value) ? '' : (string) $value;
        switch ($type) {
            case 'email':
                return filter_var($s, FILTER_VALIDATE_EMAIL) !== false;
            case 'url':
                return filter_var($s, FILTER_VALIDATE_URL) !== false;
            case 'number':
            case 'currency':
                return is_numeric($s);
            case 'date':
                return strtotime($s) !== false;
            case 'single_choice':
            case 'yes_no':
                $opts = $this->optionValues($q);
                return $opts === [] || in_array($s, $opts, true);
            case 'multi_choice':
                $opts = $this->optionValues($q);
                $vals = is_array($value) ? array_map('strval', $value) : [$s];
                foreach ($vals as $v) {
                    if ($opts !== [] && !in_array($v, $opts, true)) {
                        return false; // reject options that no longer exist in the frozen instance
                    }
                }
                return true;
            default:
                return true;
        }
    }

    /**
     * @param array<string,mixed> $q
     * @return list<string>
     */
    private function optionValues(array $q): array
    {
        if ((string) $q['type'] === 'yes_no') {
            return ['yes', 'no'];
        }

        return array_map(static fn (array $o): string => (string) $o['value'], $q['options'] ?? []);
    }

    /**
     * Propose mappings from frozen rules + answers. Conflicts with trusted
     * existing data create a pending review; empty targets are auto-populated
     * (logged). Idempotent: existing pending/decided reviews are not duplicated.
     *
     * @param array<string,mixed> $structure
     * @param array<string,mixed> $answers
     */
    private function proposeMappings(string $instanceUuid, array $structure, array $answers, string $actor): void
    {
        foreach ($structure['mappings'] ?? [] as $m) {
            $mode = (string) $m['mode'];
            if (!in_array($mode, ['direct', 'normalised', 'combined'], true)) {
                continue; // task/tag/note modes handled elsewhere
            }
            $qk = (string) $m['question_key'];
            $target = (string) $m['target'];
            $submitted = $answers[$qk] ?? null;
            if ($this->isEmpty($submitted) || !isset(self::TARGETS[$target])) {
                continue;
            }
            $submittedStr = is_array($submitted) ? implode(', ', array_map('strval', $submitted)) : (string) $submitted;
            $existing = $this->readTarget($target, $instanceUuid);
            if (trim($existing) === '') {
                // Safe empty-field population — applied but logged.
                $this->applyTarget($target, $instanceUuid, $submittedStr);
                $this->recordReview($instanceUuid, $qk, $target, '', $submittedStr, 'accepted', 'system');
                $this->event($instanceUuid, 'mapping_auto', $target . ' populated', $actor);
                continue;
            }
            if (trim($existing) === trim($submittedStr)) {
                continue; // no change
            }
            // Conflict — needs review; don't duplicate an open review.
            $open = $this->db->one("SELECT uuid FROM onboarding_mapping_reviews WHERE instance_uuid = :i AND question_key = :q AND target = :t AND decision = 'pending'", ['i' => $instanceUuid, 'q' => $qk, 't' => $target]);
            if ($open === null) {
                $this->recordReview($instanceUuid, $qk, $target, $existing, $submittedStr, 'pending', '');
            }
        }
    }

    /**
     * Generate tasks from task-mode mappings. Idempotent via a deterministic
     * source_ref so resubmission does not create duplicates.
     *
     * @param array<string,mixed> $structure
     */
    private function generateTasks(string $instanceUuid, array $structure, string $actor): void
    {
        $projectUuid = $this->projectOf($instanceUuid);
        if ($projectUuid === '') {
            return;
        }
        $answers = $this->answers($instanceUuid);
        foreach ($structure['mappings'] ?? [] as $m) {
            if ((string) $m['mode'] !== 'task') {
                continue;
            }
            $qk = (string) $m['question_key'];
            if ($this->isEmpty($answers[$qk] ?? null)) {
                continue;
            }
            $sourceRef = 'onboarding:' . $instanceUuid . ':' . $qk;
            if ($this->db->one('SELECT uuid FROM project_tasks WHERE source_ref = :r', ['r' => $sourceRef]) !== null) {
                continue; // idempotent
            }
            $this->platform->projectTasks()->create($projectUuid, [
                'title' => 'Onboarding: ' . (string) $m['target'],
                'description' => 'Generated from onboarding answer.',
                'source' => 'onboarding', 'source_ref' => $sourceRef,
            ], $actor);
        }
    }

    private function recordReview(string $instanceUuid, string $qk, string $target, string $existing, string $submitted, string $decision, string $reviewer): void
    {
        $this->db->run(
            'INSERT INTO onboarding_mapping_reviews (uuid, instance_uuid, question_key, target, existing_value, submitted_value, decision, reviewer, decided_at, created_at)
             VALUES (:uuid, :i, :qk, :t, :ex, :sub, :d, :r, :decided, :now)',
            ['uuid' => Uuid::v4(), 'i' => $instanceUuid, 'qk' => $qk, 't' => $target, 'ex' => $existing, 'sub' => $submitted, 'd' => $decision, 'r' => $reviewer, 'decided' => $decision === 'pending' ? null : Clock::nowIso(), 'now' => Clock::nowIso()]
        );
    }

    private function readTarget(string $target, string $instanceUuid): string
    {
        if (!isset(self::TARGETS[$target])) {
            return '';
        }
        [$table, $column] = self::TARGETS[$target];
        $id = $this->targetId($table, $instanceUuid);
        if ($id === '') {
            return '';
        }

        // The CRM core (contacts, companies) is flat-file; projects are still
        // database-backed.
        $record = match ($table) {
            'contacts'  => $this->platform->contacts()->find($id),
            'companies' => $this->platform->companies()->find($id),
            default     => $this->db->one("SELECT * FROM {$table} WHERE uuid = :u", ['u' => $id]),
        };

        return (string) ($record[$column] ?? '');
    }

    private function applyTarget(string $target, string $instanceUuid, string $value): void
    {
        if (!isset(self::TARGETS[$target])) {
            return;
        }
        [$table, $column] = self::TARGETS[$target];
        $id = $this->targetId($table, $instanceUuid);
        if ($id === '') {
            return;
        }

        // The CRM core (contacts, companies) is flat-file; projects are still
        // database-backed.
        match ($table) {
            'contacts'  => $this->platform->contacts()->update($id, [$column => $value]),
            'companies' => $this->platform->companies()->update($id, [$column => $value]),
            default     => $this->db->run("UPDATE {$table} SET {$column} = :v WHERE uuid = :u", ['v' => $value, 'u' => $id]),
        };
    }

    private function targetId(string $table, string $instanceUuid): string
    {
        $i = $this->db->one('SELECT project_uuid, company_uuid, contact_uuid FROM onboarding_instances WHERE uuid = :u', ['u' => $instanceUuid]);
        if ($i === null) {
            return '';
        }

        return match ($table) {
            'projects'  => (string) ($i['project_uuid'] ?? ''),
            'companies' => (string) ($i['company_uuid'] ?? ''),
            'contacts'  => (string) ($i['contact_uuid'] ?? ''),
            default     => '',
        };
    }

    private function projectOf(string $instanceUuid): string
    {
        return (string) $this->db->scalar('SELECT project_uuid FROM onboarding_instances WHERE uuid = :u', ['u' => $instanceUuid]);
    }

    /** @param array<string,mixed> $instance */
    private function isExpired(array $instance): bool
    {
        $exp = (string) ($instance['expires_at'] ?? '');

        return $exp !== '' && strtotime($exp) !== false && strtotime($exp) < time();
    }

    /**
     * @param list<string> $allowed
     * @return array<string,mixed>
     */
    private function requireStatus(string $instanceUuid, array $allowed): array
    {
        $i = $this->db->one('SELECT * FROM onboarding_instances WHERE uuid = :u', ['u' => $instanceUuid]);
        if ($i === null) {
            throw new OnboardingException(404, 'Onboarding not found.');
        }
        if (!in_array((string) $i['status'], $allowed, true)) {
            throw new OnboardingException(409, 'That action is not available in the current state.');
        }

        return $i;
    }

    private function isEmpty(mixed $value): bool
    {
        if (is_array($value)) {
            return $value === [];
        }

        return trim((string) ($value ?? '')) === '';
    }

    private function event(string $instanceUuid, string $type, string $detail, string $actor): void
    {
        $this->db->run('INSERT INTO onboarding_events (uuid, instance_uuid, type, detail, actor, created_at) VALUES (:uuid, :i, :type, :detail, :actor, :now)', ['uuid' => Uuid::v4(), 'i' => $instanceUuid, 'type' => $type, 'detail' => $detail, 'actor' => $actor, 'now' => Clock::nowIso()]);
    }

    private function nullable(mixed $value): ?string
    {
        $v = trim((string) ($value ?? ''));

        return $v === '' ? null : $v;
    }
}
