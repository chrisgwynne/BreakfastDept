<?php

declare(strict_types=1);

namespace Breakfast\Platform\Onboarding;

use Breakfast\Platform\Support\Clock;
use Breakfast\Platform\Support\FileStore;
use Breakfast\Platform\Support\Platform;
use Breakfast\Platform\Support\Uuid;

/**
 * Onboarding instances — the client-facing delivery vertical, stored as flat files.
 *
 * An instance binds to an exact frozen template version. Answers save
 * incrementally (durable, server-authoritative) with optimistic concurrency;
 * submission validates required VISIBLE questions server-side (hidden
 * conditional questions are never required) and snapshots an immutable answer
 * version. Selected answers PROPOSE field updates: when they conflict with
 * trusted existing data a review item is created — data is never silently
 * overwritten. Generated tasks are idempotent across resubmission.
 *
 * Each instance is one JSON record; its answers, mapping reviews, events, answer
 * versions and invitations live embedded in that record as native arrays.
 */
final class Onboarding
{
    private const COLLECTION = 'onboarding_instances';

    /** Mappable targets → [table, column]. Everything else routes via mode. */
    private const TARGETS = [
        'contact.phone'        => ['contacts', 'phone'],
        'company.website'      => ['companies', 'website'],
        'project.client_summary' => ['projects', 'client_summary'],
        'project.scope'        => ['projects', 'scope'],
    ];

    public function __construct(
        private readonly FileStore $store,
        private readonly Platform $platform,
    ) {
    }

    private function templates(): OnboardingTemplates
    {
        return new OnboardingTemplates($this->store);
    }

    // ==================================================================
    // Read
    // ==================================================================

    /** @return list<array<string,mixed>> */
    public function forProject(string $projectUuid): array
    {
        $rows = array_values(array_filter($this->store->all(self::COLLECTION), static fn (array $i): bool => (string) ($i['project_uuid'] ?? '') === $projectUuid));
        usort($rows, static fn ($a, $b) => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));

        return array_map(fn (array $i): array => $this->shape($i), $rows);
    }

    /** @return array<string,mixed>|null */
    public function find(string $uuid): ?array
    {
        $i = $this->store->find(self::COLLECTION, $uuid);

        return $i === null ? null : $this->shape($i);
    }

    /** @return array<string,mixed> qkey => value */
    public function answers(string $instanceUuid): array
    {
        $i = $this->store->find(self::COLLECTION, $instanceUuid);

        return $i === null ? [] : $this->decodeAnswers($i);
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
        $this->store->put(self::COLLECTION, [
            'uuid'             => $uuid,
            'template_uuid'    => $templateUuid,
            'version'          => (int) $structure['version'],
            'version_uuid'     => (string) $structure['uuid'],
            'project_uuid'     => $projectUuid,
            'company_uuid'     => $this->nullable($project['company_uuid'] ?? null),
            'contact_uuid'     => $this->nullable($project['contact_uuid'] ?? null),
            'opportunity_uuid' => $this->nullable($project['opportunity_uuid'] ?? null),
            'proposal_uuid'    => $this->nullable($project['proposal_uuid'] ?? null),
            'contract_uuid'    => $this->nullable($project['contract_uuid'] ?? null),
            'status'           => 'draft',
            'token_hash'       => '',
            'expires_at'       => null,
            'invited_at'       => null,
            'viewed_at'        => null,
            'submitted_at'     => null,
            'completed_at'     => null,
            'submission_no'    => 0,
            'revision'         => 0,
            'answers'          => [],
            'reviews'          => [],
            'events'           => [],
            'answer_versions'  => [],
            'invitations'      => [],
            'created_by'       => $actor,
            'created_at'       => $now,
            'updated_at'       => $now,
        ]);
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
        $i = $this->store->find(self::COLLECTION, $instanceUuid);
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
        $this->store->update(self::COLLECTION, $instanceUuid, static function (array $row) use ($hash, $expires, $email, $now): array {
            $row['token_hash'] = $hash;
            $row['expires_at'] = $expires;
            $row['status']     = 'invited';
            $row['invited_at'] = (string) ($row['invited_at'] ?? '') !== '' ? $row['invited_at'] : $now;
            $row['updated_at'] = $now;
            $row['revision']   = (int) ($row['revision'] ?? 0) + 1;
            // Revoke prior invitations, then record the new one.
            $invitations = is_array($row['invitations'] ?? null) ? $row['invitations'] : [];
            foreach ($invitations as &$inv) {
                if ((int) ($inv['revoked'] ?? 0) === 0) {
                    $inv['revoked'] = 1;
                }
            }
            unset($inv);
            $invitations[] = ['uuid' => Uuid::v4(), 'token_hash' => $hash, 'email' => $email, 'expires_at' => $expires, 'revoked' => 0, 'sent_at' => $now, 'created_at' => $now];
            $row['invitations'] = $invitations;

            return $row;
        });
        $this->event($instanceUuid, 'invited', 'Invitation sent to ' . $email, $actor);

        return ['instance' => $this->find($instanceUuid) ?? [], 'token' => $raw];
    }

    /** @return array<string,mixed> */
    public function withdraw(string $instanceUuid, string $actor): array
    {
        $this->store->update(self::COLLECTION, $instanceUuid, static function (array $row): array {
            $row['status']     = 'withdrawn';
            $row['token_hash'] = '';
            $row['updated_at'] = Clock::nowIso();
            $row['revision']   = (int) ($row['revision'] ?? 0) + 1;
            $invitations = is_array($row['invitations'] ?? null) ? $row['invitations'] : [];
            foreach ($invitations as &$inv) {
                $inv['revoked'] = 1;
            }
            unset($inv);
            $row['invitations'] = $invitations;

            return $row;
        });
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
        foreach ($this->store->all(self::COLLECTION) as $i) {
            if ((string) ($i['token_hash'] ?? '') === $hash) {
                return $this->isExpired($i) ? null : $this->shape($i);
            }
        }

        return null;
    }

    /** Viewing records 'viewed' — never 'in_progress'. */
    public function markViewed(string $instanceUuid): void
    {
        $this->store->update(self::COLLECTION, $instanceUuid, static function (array $row): array {
            if ((string) ($row['status'] ?? '') === 'invited') {
                $row['status'] = 'viewed';
            }
            $row['viewed_at']  = (string) ($row['viewed_at'] ?? '') !== '' ? $row['viewed_at'] : Clock::nowIso();
            $row['updated_at'] = Clock::nowIso();

            return $row;
        });
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
        $i = $this->store->find(self::COLLECTION, $instanceUuid);
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
        $this->store->update(self::COLLECTION, $instanceUuid, static function (array $row) use ($answers, $validKeys): array {
            $meaningful = false;
            $stored = is_array($row['answers'] ?? null) ? $row['answers'] : [];
            foreach ($answers as $key => $value) {
                if (!in_array((string) $key, $validKeys, true)) {
                    continue; // ignore answers to unknown/frozen-removed questions
                }
                $encoded = is_array($value) ? (json_encode(array_values($value)) ?: '') : (string) $value;
                if (trim($encoded) !== '' && $encoded !== '[]') {
                    $meaningful = true;
                }
                $stored[(string) $key] = $encoded;
            }
            $row['answers'] = $stored;
            // Meaningful client input advances invited/viewed → in_progress.
            if ($meaningful && in_array((string) ($row['status'] ?? ''), ['invited', 'viewed'], true)) {
                $row['status'] = 'in_progress';
            }
            $row['updated_at'] = Clock::nowIso();
            $row['revision']   = (int) ($row['revision'] ?? 0) + 1;

            return $row;
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
        $i = $this->store->find(self::COLLECTION, $instanceUuid);
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
        $answers = $this->decodeAnswers($i);
        $errors = $this->validate($structure ?? [], $answers);
        if ($errors !== []) {
            throw new OnboardingException(422, 'Please complete the required fields: ' . implode(', ', array_slice($errors, 0, 5)));
        }

        $now = Clock::nowIso();
        $submissionNo = 0;
        $this->store->update(self::COLLECTION, $instanceUuid, static function (array $row) use ($answers, $now, &$submissionNo): array {
            $submissionNo = (int) ($row['submission_no'] ?? 0) + 1;
            $row['answer_versions'][] = ['uuid' => Uuid::v4(), 'submission_no' => $submissionNo, 'snapshot' => $answers, 'created_at' => $now];
            $row['status']        = 'submitted';
            $row['submitted_at']  = $now;
            $row['submission_no'] = $submissionNo;
            $row['updated_at']    = $now;
            $row['revision']      = (int) ($row['revision'] ?? 0) + 1;

            return $row;
        });
        $this->event($instanceUuid, 'submitted', 'Submission #' . $submissionNo, $actor);

        // Propose mappings (creates review items for conflicts; auto-applies safe empties).
        $this->proposeMappings($instanceUuid, $structure ?? [], $answers, $actor);
        $this->generateTasks($instanceUuid, $structure ?? [], $actor);

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
        $this->setStatus((string) $i['uuid'], 'needs_clarification');
        $this->event($instanceUuid, 'clarification_requested', $note, $actor);

        return $this->find($instanceUuid) ?? [];
    }

    /** @return array<string,mixed> */
    public function startReview(string $instanceUuid, string $actor): array
    {
        $i = $this->requireStatus($instanceUuid, ['submitted']);
        $this->setStatus((string) $i['uuid'], 'under_review');
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
        $now = Clock::nowIso();
        $this->store->update(self::COLLECTION, (string) $i['uuid'], static function (array $row) use ($now): array {
            $row['status']       = 'completed';
            $row['completed_at'] = $now;
            $row['token_hash']   = '';
            $row['updated_at']   = $now;
            $row['revision']     = (int) ($row['revision'] ?? 0) + 1;
            $invitations = is_array($row['invitations'] ?? null) ? $row['invitations'] : [];
            foreach ($invitations as &$inv) {
                $inv['revoked'] = 1;
            }
            unset($inv);
            $row['invitations'] = $invitations;

            return $row;
        });
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
        [$instanceUuid, $review] = $this->findReview($reviewUuid);
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
            $this->applyTarget((string) $review['target'], $instanceUuid, $value);
        }
        $this->store->update(self::COLLECTION, $instanceUuid, static function (array $row) use ($reviewUuid, $decision, $actor): array {
            $reviews = is_array($row['reviews'] ?? null) ? $row['reviews'] : [];
            foreach ($reviews as &$r) {
                if ((string) ($r['uuid'] ?? '') === $reviewUuid) {
                    $r['decision']   = $decision;
                    $r['reviewer']   = $actor;
                    $r['decided_at'] = Clock::nowIso();
                }
            }
            unset($r);
            $row['reviews'] = $reviews;

            return $row;
        });
        $this->event($instanceUuid, 'mapping_' . $decision, (string) $review['target'], $actor);
        $this->platform->audit()->event('onboarding.mapping_' . $decision, 'project', $this->projectOf($instanceUuid), $actor, ['target' => $review['target']]);

        return $this->find($instanceUuid) ?? [];
    }

    // ==================================================================
    // Readiness
    // ==================================================================

    /** @return array{ready:bool,blockers:list<string>,percent:int} */
    public function readiness(string $instanceUuid): array
    {
        $i = $this->store->find(self::COLLECTION, $instanceUuid);
        if ($i === null) {
            return ['ready' => false, 'blockers' => ['Onboarding not found'], 'percent' => 0];
        }
        $blockers = [];
        if (!in_array((string) $i['status'], ['submitted', 'under_review', 'completed'], true)) {
            $blockers[] = 'Not yet submitted';
        }
        $pendingReviews = count(array_filter(is_array($i['reviews'] ?? null) ? $i['reviews'] : [], static fn (array $r): bool => (string) ($r['decision'] ?? '') === 'pending'));
        if ($pendingReviews > 0) {
            $blockers[] = $pendingReviews . ' mapping conflict(s) to review';
        }
        if ((string) $i['status'] === 'needs_clarification') {
            $blockers[] = 'Awaiting client clarification';
        }
        // Percent: required visible answered / required visible total.
        $structure = $this->templates()->versionStructure((string) $i['template_uuid'], (int) $i['version']);
        $answers = $this->decodeAnswers($i);
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
     * Return an instance with its answers decoded and reviews/events normalised
     * to the shape callers expect, plus live readiness.
     *
     * @param array<string,mixed> $i
     * @return array<string,mixed>
     */
    private function shape(array $i): array
    {
        $i['answers'] = $this->decodeAnswers($i);
        $reviews = is_array($i['reviews'] ?? null) ? array_values($i['reviews']) : [];
        usort($reviews, static fn ($a, $b) => strcmp((string) ($a['created_at'] ?? ''), (string) ($b['created_at'] ?? '')));
        $i['reviews'] = $reviews;
        $events = is_array($i['events'] ?? null) ? array_values($i['events']) : [];
        usort($events, static fn ($a, $b) => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));
        $i['events'] = array_slice($events, 0, 100);
        $i['readiness'] = $this->readiness((string) $i['uuid']);

        return $i;
    }

    /**
     * @param array<string,mixed> $instance
     * @return array<string,mixed> qkey => value (arrays decoded, else string)
     */
    private function decodeAnswers(array $instance): array
    {
        $out = [];
        foreach (is_array($instance['answers'] ?? null) ? $instance['answers'] : [] as $key => $raw) {
            $decoded = json_decode((string) $raw, true);
            $out[(string) $key] = is_array($decoded) ? $decoded : (string) $raw;
        }

        return $out;
    }

    private function setStatus(string $instanceUuid, string $status): void
    {
        $this->store->update(self::COLLECTION, $instanceUuid, static function (array $row) use ($status): array {
            $row['status']     = $status;
            $row['updated_at'] = Clock::nowIso();
            $row['revision']   = (int) ($row['revision'] ?? 0) + 1;

            return $row;
        });
    }

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
            if (!$this->hasOpenReview($instanceUuid, $qk, $target)) {
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
            if ($this->platform->projectTasks()->findBySourceRef($sourceRef) !== null) {
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
        $this->store->update(self::COLLECTION, $instanceUuid, static function (array $row) use ($qk, $target, $existing, $submitted, $decision, $reviewer): array {
            $row['reviews'][] = [
                'uuid' => Uuid::v4(), 'question_key' => $qk, 'target' => $target, 'existing_value' => $existing, 'submitted_value' => $submitted,
                'decision' => $decision, 'reviewer' => $reviewer, 'decided_at' => $decision === 'pending' ? null : Clock::nowIso(), 'created_at' => Clock::nowIso(),
            ];

            return $row;
        });
    }

    private function hasOpenReview(string $instanceUuid, string $qk, string $target): bool
    {
        $i = $this->store->find(self::COLLECTION, $instanceUuid);
        foreach (is_array($i['reviews'] ?? null) ? $i['reviews'] : [] as $r) {
            if ((string) ($r['question_key'] ?? '') === $qk && (string) ($r['target'] ?? '') === $target && (string) ($r['decision'] ?? '') === 'pending') {
                return true;
            }
        }

        return false;
    }

    /**
     * Locate a mapping review by id across instances.
     *
     * @return array{0:string,1:array<string,mixed>|null} [instanceUuid, review]
     */
    private function findReview(string $reviewUuid): array
    {
        foreach ($this->store->all(self::COLLECTION) as $i) {
            foreach (is_array($i['reviews'] ?? null) ? $i['reviews'] : [] as $r) {
                if ((string) ($r['uuid'] ?? '') === $reviewUuid) {
                    return [(string) $i['uuid'], $r];
                }
            }
        }

        return ['', null];
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

        // The CRM core (contacts, companies) and projects are all flat-file.
        $record = match ($table) {
            'contacts'  => $this->platform->contacts()->find($id),
            'companies' => $this->platform->companies()->find($id),
            default     => $this->platform->projects()->raw($id),
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

        // The CRM core (contacts, companies) and projects are all flat-file.
        match ($table) {
            'contacts'  => $this->platform->contacts()->update($id, [$column => $value]),
            'companies' => $this->platform->companies()->update($id, [$column => $value]),
            default     => $this->platform->projects()->update($id, [$column => $value], 'onboarding'),
        };
    }

    private function targetId(string $table, string $instanceUuid): string
    {
        $i = $this->store->find(self::COLLECTION, $instanceUuid);
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
        $i = $this->store->find(self::COLLECTION, $instanceUuid);

        return (string) ($i['project_uuid'] ?? '');
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
        $i = $this->store->find(self::COLLECTION, $instanceUuid);
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
        $this->store->update(self::COLLECTION, $instanceUuid, static function (array $row) use ($type, $detail, $actor): array {
            $row['events'][] = ['uuid' => Uuid::v4(), 'type' => $type, 'detail' => $detail, 'actor' => $actor, 'created_at' => Clock::nowIso()];

            return $row;
        });
    }

    private function nullable(mixed $value): ?string
    {
        $v = trim((string) ($value ?? ''));

        return $v === '' ? null : $v;
    }
}
