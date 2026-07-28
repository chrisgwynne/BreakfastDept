<?php

declare(strict_types=1);

namespace Breakfast\Platform\Contracts;

use Breakfast\Platform\Support\Clock;
use Breakfast\Platform\Support\FileStore;
use Breakfast\Platform\Support\Uuid;

/**
 * Contracts & electronic signature — the commitment step after an accepted
 * proposal, stored as flat files.
 *
 * A draft is freely editable. SENDING freezes an immutable version (merge fields
 * resolved, parties + clause text frozen, snapshot hashed) and mints a signing
 * token; unresolved REQUIRED merge fields block the send. Signing is a
 * deliberate, evidenced act per party (a page visit only records "viewed"); the
 * contract completes once every required party has signed. A signed contract is
 * immutable — to change it you supersede it with a new version, preserving the
 * old one. Every transition is an immutable contract_event.
 */
final class Contracts
{
    /** @var list<string> */
    public const STATUSES = ['draft', 'ready', 'sent', 'viewed', 'signed', 'declined', 'expired', 'void', 'superseded', 'withdrawn'];

    private const EDITABLE = ['draft', 'ready'];
    private const LIVE = ['sent', 'viewed'];

    public function __construct(private readonly FileStore $store)
    {
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
        $rows = $this->store->all('contracts');
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
        $c = $this->store->find('contracts', $uuid);
        if ($c === null) {
            return null;
        }
        $c['sections_list'] = $this->decodeSections($c['sections'] ?? []);

        $parties = array_values(array_filter($this->store->all('contract_parties'), static fn (array $r): bool => (string) ($r['contract_uuid'] ?? '') === $uuid));
        usort($parties, static fn ($a, $b) => (int) ($a['signing_order'] ?? 0) <=> (int) ($b['signing_order'] ?? 0));

        $events = array_values(array_filter($this->store->all('contract_events'), static fn (array $r): bool => (string) ($r['contract_uuid'] ?? '') === $uuid));
        usort($events, static fn ($a, $b) => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));

        $signatures = array_values(array_filter($this->store->all('contract_signatures'), static fn (array $r): bool => (string) ($r['contract_uuid'] ?? '') === $uuid));
        usort($signatures, static fn ($a, $b) => strcmp((string) ($a['created_at'] ?? ''), (string) ($b['created_at'] ?? '')));

        $c['parties']    = $parties;
        $c['events']     = $events;
        $c['signatures'] = $signatures;

        return $c;
    }

    /** @return array<string,mixed>|null */
    public function findByToken(string $token): ?array
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }
        foreach ($this->store->all('contracts') as $row) {
            if ((string) ($row['public_token'] ?? '') === $token) {
                return $this->find((string) $row['uuid']);
            }
        }

        return null;
    }

    // ==================================================================
    // Create / edit
    // ==================================================================

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function create(array $data, string $actor): array
    {
        $uuid = Uuid::v4();
        $now  = Clock::nowIso();

        $templateKey = (string) ($data['template_key'] ?? 'general');
        $template    = ContractTemplates::get($templateKey) ?? ContractTemplates::get('general');
        $sections    = is_array($data['sections'] ?? null) ? $data['sections'] : ($template['sections'] ?? []);

        $this->store->put('contracts', [
            'uuid'              => $uuid,
            'number'            => null,
            'status'            => 'draft',
            'title'             => trim((string) ($data['title'] ?? ($template['name'] ?? 'Contract'))),
            'template_key'      => $templateKey,
            'company_uuid'      => $this->nullable($data['company_uuid'] ?? null),
            'contact_uuid'      => $this->nullable($data['contact_uuid'] ?? null),
            'opportunity_uuid'  => $this->nullable($data['opportunity_uuid'] ?? null),
            'proposal_uuid'     => $this->nullable($data['proposal_uuid'] ?? null),
            'project_uuid'      => $this->nullable($data['project_uuid'] ?? null),
            'owner'             => trim((string) ($data['owner'] ?? $actor)),
            'currency'          => (string) ($data['currency'] ?? 'GBP'),
            'contract_value'    => max(0, (int) round(((float) ($data['contract_value'] ?? 0)) * 100)),
            'deposit_amount'    => max(0, (int) round(((float) ($data['deposit_amount'] ?? 0)) * 100)),
            'effective_date'    => $this->nullable($data['effective_date'] ?? null),
            'expiry_date'       => $this->nullable($data['expiry_date'] ?? null),
            'revision_limit'    => (int) ($data['revision_limit'] ?? ($template['default_revision_limit'] ?? 0)),
            'sections'          => $this->normaliseSections($sections),
            'governing_law'     => (string) ($data['governing_law'] ?? 'the laws of England and Wales'),
            'signature_wording' => (string) ($data['signature_wording'] ?? 'By typing my name and signing below, I confirm I have read and agree to this contract on behalf of the Client.'),
            'internal_notes'    => (string) ($data['internal_notes'] ?? ''),
            'seller_name'       => (string) ($data['seller_name'] ?? ''),
            'client_name'       => (string) ($data['client_name'] ?? ''),
            'client_email'      => (string) ($data['client_email'] ?? ''),
            'public_token'      => null,
            'snapshot'          => null,
            'snapshot_version'  => 0,
            'document_status'   => 'none',
            'signed_key'        => '',
            'signed_hash'       => '',
            'unsigned_key'      => '',
            'unsigned_hash'     => '',
            'supersedes_uuid'   => null,
            'sent_at'           => null,
            'viewed_at'         => null,
            'signed_at'         => null,
            'completed_at'      => null,
            'created_by'        => $actor,
            'created_at'        => $now,
            'updated_at'        => $now,
        ]);

        // Default parties: the client signs first (order 1) through the hosted
        // flow, then Breakfast countersigns (order 2) in the admin.
        $this->addParty($uuid, 'client', (string) ($data['client_name'] ?? ''), (string) ($data['client_email'] ?? ''), 1, true);
        $this->addParty($uuid, 'breakfast', (string) ($data['seller_name'] ?? 'Breakfast'), '', 2, true);

        $this->event($uuid, 'created', 'Contract drafted', $actor);

        return $this->find($uuid) ?? [];
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function update(string $uuid, array $data, string $actor): array
    {
        $c = $this->store->find('contracts', $uuid);
        if ($c === null) {
            throw new ContractException(404, 'Contract not found.');
        }
        if (!in_array((string) $c['status'], self::EDITABLE, true)) {
            throw new ContractException(409, 'A sent contract can’t be edited — supersede it with a new version.');
        }

        $this->store->update('contracts', $uuid, function (array $row) use ($data): array {
            foreach (['title', 'governing_law', 'signature_wording', 'internal_notes', 'client_name', 'client_email', 'seller_name'] as $col) {
                if (array_key_exists($col, $data)) {
                    $row[$col] = (string) $data[$col];
                }
            }
            foreach (['company_uuid', 'contact_uuid', 'opportunity_uuid', 'proposal_uuid', 'project_uuid', 'effective_date', 'expiry_date'] as $col) {
                if (array_key_exists($col, $data)) {
                    $row[$col] = $this->nullable($data[$col]);
                }
            }
            if (array_key_exists('contract_value', $data)) {
                $row['contract_value'] = max(0, (int) round(((float) $data['contract_value']) * 100));
            }
            if (array_key_exists('deposit_amount', $data)) {
                $row['deposit_amount'] = max(0, (int) round(((float) $data['deposit_amount']) * 100));
            }
            if (array_key_exists('revision_limit', $data)) {
                $row['revision_limit'] = (int) $data['revision_limit'];
            }
            if (array_key_exists('sections', $data) && is_array($data['sections'])) {
                $row['sections'] = $this->normaliseSections($data['sections']);
            }
            $row['updated_at'] = Clock::nowIso();

            return $row;
        });

        // Keep the client party in sync with client_name/email while editable.
        if (array_key_exists('client_name', $data) || array_key_exists('client_email', $data)) {
            foreach ($this->store->all('contract_parties') as $party) {
                if ((string) ($party['contract_uuid'] ?? '') === $uuid && (string) ($party['role'] ?? '') === 'client') {
                    $this->store->update('contract_parties', (string) $party['uuid'], static function (array $row) use ($data): array {
                        if (array_key_exists('client_name', $data)) {
                            $row['name'] = (string) $data['client_name'];
                        }
                        if (array_key_exists('client_email', $data)) {
                            $row['email'] = (string) $data['client_email'];
                        }

                        return $row;
                    });
                }
            }
        }

        return $this->find($uuid) ?? [];
    }

    /** @return array<string,mixed> */
    public function markReady(string $uuid, string $actor): array
    {
        $this->transition($uuid, ['draft'], 'ready', 'ready', 'Marked ready to send', $actor);

        return $this->find($uuid) ?? [];
    }

    // ==================================================================
    // Send — freeze the immutable version
    // ==================================================================

    /**
     * Resolve merge fields against the contract's data, freeze the version and
     * mint a signing token. Unresolved REQUIRED merge fields block the send.
     *
     * @param array<string,string> $mergeValues
     * @return array<string,mixed>
     */
    public function send(string $uuid, string $actor, array $mergeValues, string $prefix = 'CTR'): array
    {
        $c = $this->store->find('contracts', $uuid);
        if ($c === null) {
            throw new ContractException(404, 'Contract not found.');
        }
        if (!in_array((string) $c['status'], ['draft', 'ready'], true)) {
            throw new ContractException(409, 'This contract has already been sent.');
        }

        // Resolve merge fields across enabled sections; collect any missing.
        $sections = $this->decodeSections($c['sections'] ?? []);
        $resolved = [];
        $missing  = [];
        foreach ($sections as $s) {
            if (($s['optional'] ?? false) && !($s['enabled'] ?? true)) {
                continue;
            }
            $m = ContractTemplates::merge((string) ($s['body'] ?? ''), $mergeValues);
            $missing = array_merge($missing, $m['missing']);
            $resolved[] = ['key' => $s['key'] ?? '', 'heading' => (string) ($s['heading'] ?? ''), 'body' => $m['text']];
        }
        $missing = array_values(array_unique($missing));
        if ($missing !== []) {
            throw new ContractException(422, 'Please resolve these details before sending: ' . implode(', ', $missing) . '.');
        }

        $parties = array_values(array_filter($this->store->all('contract_parties'), static fn (array $r): bool => (string) ($r['contract_uuid'] ?? '') === $uuid));
        usort($parties, static fn ($a, $b) => (int) ($a['signing_order'] ?? 0) <=> (int) ($b['signing_order'] ?? 0));
        // Every required client party needs an email to be reachable.
        foreach ($parties as $party) {
            if (str_starts_with((string) ($party['role'] ?? ''), 'client') && (int) ($party['required'] ?? 0) === 1 && trim((string) ($party['email'] ?? '')) === '') {
                throw new ContractException(422, 'Add the client’s email before sending.');
            }
        }

        $number = ($c['number'] ?? null) ?: $this->allocateNumber($prefix, (int) date('Y'));
        $token  = (string) (($c['public_token'] ?? null) ?: bin2hex(random_bytes(20)));
        $snapshot = ContractSnapshot::build($c, $resolved, $mergeValues, $parties);
        $now = Clock::nowIso();

        $this->store->update('contracts', $uuid, static function (array $row) use ($number, $token, $snapshot, $now): array {
            $row['number']           = $number;
            $row['status']           = 'sent';
            $row['public_token']     = $token;
            $row['snapshot']         = $snapshot;
            $row['snapshot_version'] = ContractSnapshot::VERSION;
            $row['document_status']  = 'pending';
            $row['sent_at']          = $row['sent_at'] ?? $now;
            $row['updated_at']       = $now;

            return $row;
        });
        $this->event($uuid, 'sent', 'Sent as ' . $number, $actor);

        return $this->find($uuid) ?? [];
    }

    /** A signer opened the contract link — a view, never a signature. */
    public function markViewed(string $uuid): void
    {
        $now = Clock::nowIso();
        $this->store->update('contracts', $uuid, static function (array $row) use ($now): array {
            if ((string) ($row['status'] ?? '') === 'sent') {
                $row['status'] = 'viewed';
            }
            $row['viewed_at']  = $row['viewed_at'] ?? $now;
            $row['updated_at'] = $now;

            return $row;
        });
        $this->event($uuid, 'viewed', 'Opened by a signer', 'client');
    }

    // ==================================================================
    // Signing
    // ==================================================================

    /**
     * Record a genuine signature for a party and advance the contract. Returns
     * ['contract'=>..., 'completed'=>bool]. Ordered signing is enforced: a party
     * can only sign once all earlier required parties have.
     *
     * @param array<string,mixed> $evidence
     * @return array{contract:array<string,mixed>,completed:bool}
     */
    public function sign(string $uuid, string $partyUuid, array $evidence): array
    {
        $c = $this->store->find('contracts', $uuid);
        if ($c === null) {
            throw new ContractException(404, 'Contract not found.');
        }
        if (in_array((string) $c['status'], ['void', 'declined', 'expired', 'withdrawn', 'superseded'], true)) {
            throw new ContractException(409, 'This contract can no longer be signed.');
        }
        if (($c['expiry_date'] ?? null) !== null && (string) $c['expiry_date'] !== '' && (string) $c['expiry_date'] < date('Y-m-d')) {
            throw new ContractException(410, 'This contract has expired.');
        }
        $party = $this->store->find('contract_parties', $partyUuid);
        if ($party === null || (string) ($party['contract_uuid'] ?? '') !== $uuid) {
            throw new ContractException(404, 'Signer not found.');
        }
        if ((string) $party['status'] === 'signed') {
            throw new ContractException(409, 'This party has already signed.');
        }
        // Ordered signing: all earlier required parties must have signed.
        $order = (int) ($party['signing_order'] ?? 0);
        $earlier = count(array_filter($this->store->all('contract_parties'), static fn (array $r): bool => (string) ($r['contract_uuid'] ?? '') === $uuid
            && (int) ($r['required'] ?? 0) === 1
            && (string) ($r['status'] ?? '') !== 'signed'
            && (int) ($r['signing_order'] ?? 0) < $order));
        if ($earlier > 0) {
            throw new ContractException(409, 'An earlier signatory needs to sign first.');
        }
        $name = trim((string) ($evidence['name'] ?? ''));
        if ($name === '') {
            throw new ContractException(422, 'Please enter your name to sign.');
        }

        $snapshot = $c['snapshot'] ?? null;
        $snapJson = is_array($snapshot) ? (json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '') : (string) $snapshot;
        $hash     = hash('sha256', $snapJson);
        $now      = Clock::nowIso();
        $method   = in_array(($evidence['method'] ?? 'typed'), ['typed', 'drawn'], true) ? (string) ($evidence['method'] ?? 'typed') : 'typed';

        $this->store->put('contract_signatures', [
            'uuid'             => Uuid::v4(),
            'contract_uuid'    => $uuid,
            'party_uuid'       => $partyUuid,
            'snapshot_version' => (int) ($c['snapshot_version'] ?? 0),
            'signer_name'      => $name,
            'signer_email'     => trim((string) ($evidence['email'] ?? (string) $party['email'])),
            'signer_role'      => (string) $party['role'],
            'contact_uuid'     => $this->nullable($c['contact_uuid'] ?? null),
            'method'           => $method,
            'wording'          => mb_substr((string) ($evidence['wording'] ?? (string) $c['signature_wording']), 0, 1000),
            'ip_hash'          => (string) ($evidence['ip_hash'] ?? ''),
            'user_agent'       => mb_substr((string) ($evidence['user_agent'] ?? ''), 0, 200),
            'token_id'         => (string) ($evidence['token_id'] ?? ''),
            'document_hash'    => $hash,
            'signed_at'        => $now,
            'created_at'       => $now,
        ]);
        $this->store->update('contract_parties', $partyUuid, static function (array $row) use ($now): array {
            $row['status']    = 'signed';
            $row['signed_at'] = $now;

            return $row;
        });

        $role = (string) $party['role'];
        if ($role === 'breakfast') {
            $this->event($uuid, 'countersigned', 'Countersigned by ' . $name, $name);
        } else {
            $this->event($uuid, 'signed_by_client', 'Signed by ' . $name, 'client');
            if ((string) $c['status'] !== 'signed') {
                $this->store->update('contracts', $uuid, static function (array $row) use ($now): array {
                    $row['status']     = 'signed';
                    $row['signed_at']  = $row['signed_at'] ?? $now;
                    $row['updated_at'] = $now;

                    return $row;
                });
            }
        }

        $pending = count(array_filter($this->store->all('contract_parties'), static fn (array $r): bool => (string) ($r['contract_uuid'] ?? '') === $uuid
            && (int) ($r['required'] ?? 0) === 1
            && (string) ($r['status'] ?? '') !== 'signed'));
        $completed = $pending === 0;
        if ($completed) {
            $this->store->update('contracts', $uuid, static function (array $row) use ($now): array {
                $row['completed_at'] = $row['completed_at'] ?? $now;
                $row['updated_at']   = $now;

                return $row;
            });
            $this->event($uuid, 'completed', 'All required parties have signed', 'system');
        }

        return ['contract' => $this->find($uuid) ?? [], 'completed' => $completed];
    }

    /**
     * Sign as the Breakfast party (internal countersignature).
     *
     * @param array<string,mixed> $evidence
     * @return array{contract:array<string,mixed>,completed:bool}
     */
    public function signInternal(string $uuid, array $evidence): array
    {
        foreach ($this->store->all('contract_parties') as $party) {
            if ((string) ($party['contract_uuid'] ?? '') === $uuid && (string) ($party['role'] ?? '') === 'breakfast') {
                return $this->sign($uuid, (string) $party['uuid'], $evidence);
            }
        }

        throw new ContractException(404, 'No Breakfast signatory on this contract.');
    }

    /** @return array<string,mixed> */
    public function decline(string $uuid, string $reason, string $actorType = 'client'): array
    {
        $this->assertStatus($uuid, self::LIVE, 'Only a live contract can be declined.');
        $this->setStatus($uuid, 'declined');
        $this->event($uuid, 'declined', trim($reason) !== '' ? 'Declined: ' . trim($reason) : 'Declined', $actorType);

        return $this->find($uuid) ?? [];
    }

    /** @return array<string,mixed> */
    public function withdraw(string $uuid, string $actor): array
    {
        $this->assertStatus($uuid, ['sent', 'viewed', 'ready'], 'Only an open contract can be withdrawn.');
        $this->setStatus($uuid, 'withdrawn');
        $this->event($uuid, 'withdrawn', 'Withdrawn by ' . $actor, $actor);

        return $this->find($uuid) ?? [];
    }

    /**
     * Void a contract (requires a reason + permission enforced at the API).
     *
     * @return array<string,mixed>
     */
    public function void(string $uuid, string $reason, string $actor): array
    {
        if ($this->store->find('contracts', $uuid) === null) {
            throw new ContractException(404, 'Contract not found.');
        }
        if (trim($reason) === '') {
            throw new ContractException(422, 'A reason is required to void a contract.');
        }
        $this->setStatus($uuid, 'void');
        $this->event($uuid, 'voided', 'Voided: ' . trim($reason), $actor);

        return $this->find($uuid) ?? [];
    }

    /**
     * Create a fresh editable draft that supersedes this contract, preserving the
     * old (sent/signed) version. Requires a reason.
     *
     * @return array<string,mixed>
     */
    public function supersede(string $uuid, string $reason, string $actor): array
    {
        $c = $this->find($uuid);
        if ($c === null) {
            throw new ContractException(404, 'Contract not found.');
        }
        if (trim($reason) === '') {
            throw new ContractException(422, 'A reason is required to supersede a contract.');
        }
        $copy = $this->create([
            'title' => (string) $c['title'] . ' (revised)',
            'template_key' => $c['template_key'], 'company_uuid' => $c['company_uuid'], 'contact_uuid' => $c['contact_uuid'],
            'opportunity_uuid' => $c['opportunity_uuid'], 'proposal_uuid' => $c['proposal_uuid'], 'project_uuid' => $c['project_uuid'],
            'owner' => $c['owner'], 'currency' => $c['currency'],
            'contract_value' => (int) $c['contract_value'] / 100, 'deposit_amount' => (int) $c['deposit_amount'] / 100,
            'revision_limit' => $c['revision_limit'], 'sections' => $c['sections_list'],
            'governing_law' => $c['governing_law'], 'signature_wording' => $c['signature_wording'],
            'seller_name' => $c['seller_name'], 'client_name' => $c['client_name'], 'client_email' => $c['client_email'],
            'effective_date' => $c['effective_date'], 'expiry_date' => $c['expiry_date'],
        ], $actor);
        $copyUuid = (string) $copy['uuid'];
        $this->store->update('contracts', $copyUuid, static function (array $row) use ($uuid): array {
            $row['supersedes_uuid'] = $uuid;

            return $row;
        });

        if (in_array((string) $c['status'], ['sent', 'viewed', 'ready', 'signed'], true)) {
            $this->setStatus($uuid, 'superseded');
            $this->event($uuid, 'superseded', 'Superseded: ' . trim($reason), $actor);
        }

        return $this->find($copyUuid) ?? [];
    }

    // ==================================================================
    // Document hooks
    // ==================================================================

    /** @return array<string,mixed>|null */
    public function snapshot(string $uuid): ?array
    {
        $c = $this->store->find('contracts', $uuid);
        $snap = $c['snapshot'] ?? null;

        return is_array($snap) && $snap !== [] ? $snap : null;
    }

    public function setDocument(string $uuid, string $which, string $key, string $hash, string $status): void
    {
        $col = $which === 'signed' ? 'signed' : 'unsigned';
        $this->store->update('contracts', $uuid, static function (array $row) use ($col, $key, $hash, $status): array {
            $row[$col . '_key']    = $key;
            $row[$col . '_hash']   = $hash;
            $row['document_status'] = $status;
            $row['updated_at']      = Clock::nowIso();

            return $row;
        });
    }

    public function setDocumentStatus(string $uuid, string $status): void
    {
        $this->store->update('contracts', $uuid, static function (array $row) use ($status): array {
            $row['document_status'] = $status;
            $row['updated_at']      = Clock::nowIso();

            return $row;
        });
    }

    public function logEvent(string $uuid, string $type, string $detail, string $actor): void
    {
        $this->event($uuid, $type, $detail, $actor);
    }

    // ==================================================================
    // Internals
    // ==================================================================

    private function addParty(string $contractUuid, string $role, string $name, string $email, int $order, bool $required): void
    {
        $this->store->put('contract_parties', [
            'uuid'          => Uuid::v4(),
            'contract_uuid' => $contractUuid,
            'role'          => $role,
            'name'          => $name,
            'email'         => $email,
            'signing_order' => $order,
            'required'      => $required ? 1 : 0,
            'status'        => 'pending',
            'signed_at'     => null,
            'created_at'    => Clock::nowIso(),
        ]);
    }

    /**
     * @param mixed $sections
     * @return list<array{key:string,heading:string,body:string,optional:bool,enabled:bool}>
     */
    private function normaliseSections(mixed $sections): array
    {
        if (!is_array($sections)) {
            return [];
        }
        $out = [];
        foreach ($sections as $s) {
            if (!is_array($s)) {
                continue;
            }
            $out[] = [
                'key'      => (string) ($s['key'] ?? 'section'),
                'heading'  => (string) ($s['heading'] ?? ''),
                'body'     => (string) ($s['body'] ?? ''),
                'optional' => (bool) ($s['optional'] ?? false),
                'enabled'  => (bool) ($s['enabled'] ?? true),
            ];
        }

        return $out;
    }

    /**
     * @param mixed $sections native array (from the file) or a legacy JSON string
     * @return list<array{key:string,heading:string,body:string,optional:bool,enabled:bool}>
     */
    private function decodeSections(mixed $sections): array
    {
        if (is_string($sections)) {
            $sections = json_decode($sections, true);
        }

        return $this->normaliseSections($sections);
    }

    private function allocateNumber(string $prefix, int $year): string
    {
        $seq = $this->store->bump('contract_sequences', $prefix . '-' . $year);

        return sprintf('%s-%d-%04d', $prefix, $year, $seq);
    }

    /** @param list<string> $allowed */
    private function assertStatus(string $uuid, array $allowed, string $message): void
    {
        $c = $this->store->find('contracts', $uuid);
        if ($c === null) {
            throw new ContractException(404, 'Contract not found.');
        }
        if (!in_array((string) ($c['status'] ?? ''), $allowed, true)) {
            throw new ContractException(409, $message);
        }
    }

    private function setStatus(string $uuid, string $status): void
    {
        $this->store->update('contracts', $uuid, static function (array $row) use ($status): array {
            $row['status']     = $status;
            $row['updated_at'] = Clock::nowIso();

            return $row;
        });
    }

    /** @param list<string> $from */
    private function transition(string $uuid, array $from, string $to, string $eventType, string $detail, string $actor): void
    {
        $this->assertStatus($uuid, $from, 'This contract can’t change to ' . $to . ' from its current state.');
        $this->setStatus($uuid, $to);
        $this->event($uuid, $eventType, $detail, $actor);
    }

    private function event(string $uuid, string $type, string $detail, string $actor): void
    {
        $this->store->put('contract_events', [
            'uuid'          => Uuid::v4(),
            'contract_uuid' => $uuid,
            'type'          => $type,
            'detail'        => $detail,
            'actor'         => $actor,
            'created_at'    => Clock::nowIso(),
        ]);
    }

    private function nullable(mixed $value): ?string
    {
        $v = trim((string) ($value ?? ''));

        return $v === '' ? null : $v;
    }
}
