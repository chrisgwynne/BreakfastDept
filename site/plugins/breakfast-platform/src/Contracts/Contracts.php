<?php

declare(strict_types=1);

namespace Breakfast\Platform\Contracts;

use Breakfast\Platform\Support\Clock;
use Breakfast\Platform\Support\Database;
use Breakfast\Platform\Support\Uuid;

/**
 * Contracts & electronic signature — the commitment step after an accepted
 * proposal.
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

    public function __construct(private readonly Database $db)
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
        $where  = ['1 = 1'];
        $params = [];
        if (!empty($filters['status'])) {
            $where[] = 'status = :status';
            $params['status'] = (string) $filters['status'];
        }
        $params['l'] = (int) ($filters['limit'] ?? 100);

        return $this->db->all('SELECT * FROM contracts WHERE ' . implode(' AND ', $where) . ' ORDER BY created_at DESC LIMIT :l', $params);
    }

    /** @return array<string,mixed>|null */
    public function find(string $uuid): ?array
    {
        $c = $this->db->one('SELECT * FROM contracts WHERE uuid = :u', ['u' => $uuid]);
        if ($c === null) {
            return null;
        }
        $c['sections_list'] = $this->decodeSections((string) $c['sections']);
        $c['parties']       = $this->db->all('SELECT * FROM contract_parties WHERE contract_uuid = :u ORDER BY signing_order ASC', ['u' => $uuid]);
        $c['events']        = $this->db->all('SELECT * FROM contract_events WHERE contract_uuid = :u ORDER BY created_at DESC', ['u' => $uuid]);
        $c['signatures']    = $this->db->all('SELECT * FROM contract_signatures WHERE contract_uuid = :u ORDER BY created_at ASC', ['u' => $uuid]);

        return $c;
    }

    /** @return array<string,mixed>|null */
    public function findByToken(string $token): ?array
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }
        $row = $this->db->one('SELECT uuid FROM contracts WHERE public_token = :t', ['t' => $token]);

        return $row !== null ? $this->find((string) $row['uuid']) : null;
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

        $this->db->run(
            'INSERT INTO contracts
                (uuid, status, title, template_key, company_uuid, contact_uuid, opportunity_uuid, proposal_uuid, project_uuid,
                 owner, currency, contract_value, deposit_amount, effective_date, expiry_date, revision_limit,
                 sections, governing_law, signature_wording, internal_notes, seller_name, client_name, client_email,
                 created_by, created_at, updated_at)
             VALUES
                (:uuid, \'draft\', :title, :tpl, :company, :contact, :opp, :proposal, :project,
                 :owner, :currency, :value, :deposit, :eff, :exp, :rev,
                 :sections, :law, :sig, :notes, :seller, :cname, :cemail,
                 :actor, :now, :now)',
            [
                'uuid'     => $uuid,
                'title'    => trim((string) ($data['title'] ?? ($template['name'] ?? 'Contract'))),
                'tpl'      => $templateKey,
                'company'  => $this->nullable($data['company_uuid'] ?? null),
                'contact'  => $this->nullable($data['contact_uuid'] ?? null),
                'opp'      => $this->nullable($data['opportunity_uuid'] ?? null),
                'proposal' => $this->nullable($data['proposal_uuid'] ?? null),
                'project'  => $this->nullable($data['project_uuid'] ?? null),
                'owner'    => trim((string) ($data['owner'] ?? $actor)),
                'currency' => (string) ($data['currency'] ?? 'GBP'),
                'value'    => max(0, (int) round(((float) ($data['contract_value'] ?? 0)) * 100)),
                'deposit'  => max(0, (int) round(((float) ($data['deposit_amount'] ?? 0)) * 100)),
                'eff'      => $this->nullable($data['effective_date'] ?? null),
                'exp'      => $this->nullable($data['expiry_date'] ?? null),
                'rev'      => (int) ($data['revision_limit'] ?? ($template['default_revision_limit'] ?? 0)),
                'sections' => json_encode($this->normaliseSections($sections), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]',
                'law'      => (string) ($data['governing_law'] ?? 'the laws of England and Wales'),
                'sig'      => (string) ($data['signature_wording'] ?? 'By typing my name and signing below, I confirm I have read and agree to this contract on behalf of the Client.'),
                'notes'    => (string) ($data['internal_notes'] ?? ''),
                'seller'   => (string) ($data['seller_name'] ?? ''),
                'cname'    => (string) ($data['client_name'] ?? ''),
                'cemail'   => (string) ($data['client_email'] ?? ''),
                'actor'    => $actor,
                'now'      => $now,
            ]
        );

        // Default parties: Breakfast (order 1) + client (order 2). Ordered signing.
        $this->addParty($uuid, 'breakfast', (string) ($data['seller_name'] ?? 'Breakfast'), '', 1, true);
        $this->addParty($uuid, 'client', (string) ($data['client_name'] ?? ''), (string) ($data['client_email'] ?? ''), 2, true);

        $this->event($uuid, 'created', 'Contract drafted', $actor);

        return $this->find($uuid) ?? [];
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function update(string $uuid, array $data, string $actor): array
    {
        $c = $this->db->one('SELECT status FROM contracts WHERE uuid = :u', ['u' => $uuid]);
        if ($c === null) {
            throw new ContractException(404, 'Contract not found.');
        }
        if (!in_array((string) $c['status'], self::EDITABLE, true)) {
            throw new ContractException(409, 'A sent contract can’t be edited — supersede it with a new version.');
        }

        $text = ['title' => 'title', 'governing_law' => 'governing_law', 'signature_wording' => 'signature_wording', 'internal_notes' => 'internal_notes', 'client_name' => 'client_name', 'client_email' => 'client_email', 'seller_name' => 'seller_name'];
        $refs = ['company_uuid', 'contact_uuid', 'opportunity_uuid', 'proposal_uuid', 'project_uuid', 'effective_date', 'expiry_date'];
        $sets = [];
        $params = ['u' => $uuid, 'now' => Clock::nowIso()];
        foreach ($text as $in => $col) {
            if (array_key_exists($in, $data)) {
                $sets[] = "$col = :$col";
                $params[$col] = (string) $data[$in];
            }
        }
        foreach ($refs as $col) {
            if (array_key_exists($col, $data)) {
                $sets[] = "$col = :$col";
                $params[$col] = $this->nullable($data[$col]);
            }
        }
        if (array_key_exists('contract_value', $data)) {
            $sets[] = 'contract_value = :cv';
            $params['cv'] = max(0, (int) round(((float) $data['contract_value']) * 100));
        }
        if (array_key_exists('deposit_amount', $data)) {
            $sets[] = 'deposit_amount = :dep';
            $params['dep'] = max(0, (int) round(((float) $data['deposit_amount']) * 100));
        }
        if (array_key_exists('revision_limit', $data)) {
            $sets[] = 'revision_limit = :rev';
            $params['rev'] = (int) $data['revision_limit'];
        }
        if (array_key_exists('sections', $data) && is_array($data['sections'])) {
            $sets[] = 'sections = :sections';
            $params['sections'] = json_encode($this->normaliseSections($data['sections']), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]';
        }
        if ($sets !== []) {
            $this->db->run('UPDATE contracts SET ' . implode(', ', $sets) . ', updated_at = :now WHERE uuid = :u', $params);
        }
        // Keep the client party in sync with client_name/email while editable.
        if (array_key_exists('client_name', $data) || array_key_exists('client_email', $data)) {
            $this->db->run(
                "UPDATE contract_parties SET name = COALESCE(:n, name), email = COALESCE(:e, email) WHERE contract_uuid = :u AND role = 'client'",
                ['n' => array_key_exists('client_name', $data) ? (string) $data['client_name'] : null, 'e' => array_key_exists('client_email', $data) ? (string) $data['client_email'] : null, 'u' => $uuid]
            );
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
        return $this->db->transaction(function (Database $db) use ($uuid, $actor, $mergeValues, $prefix): array {
            $c = $db->one('SELECT * FROM contracts WHERE uuid = :u', ['u' => $uuid]);
            if ($c === null) {
                throw new ContractException(404, 'Contract not found.');
            }
            if (!in_array((string) $c['status'], ['draft', 'ready'], true)) {
                throw new ContractException(409, 'This contract has already been sent.');
            }

            // Resolve merge fields across enabled sections; collect any missing.
            $sections = $this->decodeSections((string) $c['sections']);
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
            // Every required client party needs an email to be reachable.
            $noEmail = (int) $db->scalar("SELECT COUNT(*) FROM contract_parties WHERE contract_uuid = :u AND role LIKE 'client%' AND required = 1 AND TRIM(email) = ''", ['u' => $uuid]);
            if ($noEmail > 0) {
                throw new ContractException(422, 'Add the client’s email before sending.');
            }

            $number = $c['number'] ?: $this->allocateNumber($db, $prefix, (int) date('Y'));
            $token  = (string) ($c['public_token'] ?: bin2hex(random_bytes(20)));
            $snapshot = ContractSnapshot::build($c, $resolved, $mergeValues, $db->all('SELECT * FROM contract_parties WHERE contract_uuid = :u ORDER BY signing_order ASC', ['u' => $uuid]));

            $db->run(
                "UPDATE contracts SET number = :num, status = 'sent', public_token = :tok, snapshot = :snap,
                    snapshot_version = :sv, document_status = 'pending', sent_at = COALESCE(sent_at, :now), updated_at = :now
                 WHERE uuid = :u",
                ['num' => $number, 'tok' => $token, 'snap' => json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '', 'sv' => ContractSnapshot::VERSION, 'now' => Clock::nowIso(), 'u' => $uuid]
            );
            $this->event($uuid, 'sent', 'Sent as ' . $number, $actor);

            return $this->find($uuid) ?? [];
        });
    }

    /** A signer opened the contract link — a view, never a signature. */
    public function markViewed(string $uuid): void
    {
        $this->db->run(
            "UPDATE contracts SET status = CASE WHEN status = 'sent' THEN 'viewed' ELSE status END,
                viewed_at = COALESCE(viewed_at, :now), updated_at = :now WHERE uuid = :u",
            ['now' => Clock::nowIso(), 'u' => $uuid]
        );
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
        return $this->db->transaction(function (Database $db) use ($uuid, $partyUuid, $evidence): array {
            $c = $db->one('SELECT * FROM contracts WHERE uuid = :u', ['u' => $uuid]);
            if ($c === null) {
                throw new ContractException(404, 'Contract not found.');
            }
            if (in_array((string) $c['status'], ['void', 'declined', 'expired', 'withdrawn', 'superseded'], true)) {
                throw new ContractException(409, 'This contract can no longer be signed.');
            }
            if ($c['expiry_date'] !== null && $c['expiry_date'] !== '' && (string) $c['expiry_date'] < date('Y-m-d')) {
                throw new ContractException(410, 'This contract has expired.');
            }
            $party = $db->one('SELECT * FROM contract_parties WHERE uuid = :p AND contract_uuid = :u', ['p' => $partyUuid, 'u' => $uuid]);
            if ($party === null) {
                throw new ContractException(404, 'Signer not found.');
            }
            if ((string) $party['status'] === 'signed') {
                throw new ContractException(409, 'This party has already signed.');
            }
            // Ordered signing: all earlier required parties must have signed.
            $earlier = (int) $db->scalar(
                "SELECT COUNT(*) FROM contract_parties WHERE contract_uuid = :u AND required = 1 AND status <> 'signed' AND signing_order < :o",
                ['u' => $uuid, 'o' => (int) $party['signing_order']]
            );
            if ($earlier > 0) {
                throw new ContractException(409, 'An earlier signatory needs to sign first.');
            }
            $name = trim((string) ($evidence['name'] ?? ''));
            if ($name === '') {
                throw new ContractException(422, 'Please enter your name to sign.');
            }

            $snapshot = (string) $c['snapshot'];
            $hash     = hash('sha256', $snapshot);
            $now      = Clock::nowIso();

            $db->run(
                'INSERT INTO contract_signatures
                    (uuid, contract_uuid, party_uuid, snapshot_version, signer_name, signer_email, signer_role, contact_uuid,
                     method, wording, ip_hash, user_agent, token_id, document_hash, signed_at, created_at)
                 VALUES (:uuid, :c, :p, :sv, :name, :email, :role, :contact, :method, :wording, :ip, :ua, :tok, :hash, :now, :now)',
                [
                    'uuid' => Uuid::v4(), 'c' => $uuid, 'p' => $partyUuid, 'sv' => (int) $c['snapshot_version'],
                    'name' => $name, 'email' => trim((string) ($evidence['email'] ?? (string) $party['email'])),
                    'role' => (string) $party['role'], 'contact' => $this->nullable($c['contact_uuid'] ?? null),
                    'method' => in_array(($evidence['method'] ?? 'typed'), ['typed', 'drawn'], true) ? (string) $evidence['method'] : 'typed',
                    'wording' => mb_substr((string) ($evidence['wording'] ?? (string) $c['signature_wording']), 0, 1000),
                    'ip' => (string) ($evidence['ip_hash'] ?? ''), 'ua' => mb_substr((string) ($evidence['user_agent'] ?? ''), 0, 200),
                    'tok' => (string) ($evidence['token_id'] ?? ''), 'hash' => $hash, 'now' => $now,
                ]
            );
            $db->run("UPDATE contract_parties SET status = 'signed', signed_at = :now WHERE uuid = :p", ['now' => $now, 'p' => $partyUuid]);

            $role = (string) $party['role'];
            if ($role === 'breakfast') {
                $this->event($uuid, 'countersigned', 'Countersigned by ' . $name, $name);
            } else {
                $this->event($uuid, 'signed_by_client', 'Signed by ' . $name, 'client');
                if ((string) $c['status'] !== 'signed') {
                    $db->run("UPDATE contracts SET status = 'signed', signed_at = COALESCE(signed_at, :now), updated_at = :now WHERE uuid = :u", ['now' => $now, 'u' => $uuid]);
                }
            }

            $pending = (int) $db->scalar("SELECT COUNT(*) FROM contract_parties WHERE contract_uuid = :u AND required = 1 AND status <> 'signed'", ['u' => $uuid]);
            $completed = $pending === 0;
            if ($completed) {
                $db->run("UPDATE contracts SET completed_at = COALESCE(completed_at, :now), updated_at = :now WHERE uuid = :u", ['now' => $now, 'u' => $uuid]);
                $this->event($uuid, 'completed', 'All required parties have signed', 'system');
            }

            return ['contract' => $this->find($uuid) ?? [], 'completed' => $completed];
        });
    }

    /**
     * Sign as the Breakfast party (internal countersignature).
     *
     * @param array<string,mixed> $evidence
     * @return array{contract:array<string,mixed>,completed:bool}
     */
    public function signInternal(string $uuid, array $evidence): array
    {
        $party = $this->db->one("SELECT uuid FROM contract_parties WHERE contract_uuid = :u AND role = 'breakfast' LIMIT 1", ['u' => $uuid]);
        if ($party === null) {
            throw new ContractException(404, 'No Breakfast signatory on this contract.');
        }

        return $this->sign($uuid, (string) $party['uuid'], $evidence);
    }

    /** @return array<string,mixed> */
    public function decline(string $uuid, string $reason, string $actorType = 'client'): array
    {
        $this->assertStatus($uuid, self::LIVE, 'Only a live contract can be declined.');
        $this->db->run("UPDATE contracts SET status = 'declined', updated_at = :now WHERE uuid = :u", ['now' => Clock::nowIso(), 'u' => $uuid]);
        $this->event($uuid, 'declined', trim($reason) !== '' ? 'Declined: ' . trim($reason) : 'Declined', $actorType);

        return $this->find($uuid) ?? [];
    }

    /** @return array<string,mixed> */
    public function withdraw(string $uuid, string $actor): array
    {
        $this->assertStatus($uuid, ['sent', 'viewed', 'ready'], 'Only an open contract can be withdrawn.');
        $this->db->run("UPDATE contracts SET status = 'withdrawn', updated_at = :now WHERE uuid = :u", ['now' => Clock::nowIso(), 'u' => $uuid]);
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
        if ($this->db->one('SELECT uuid FROM contracts WHERE uuid = :u', ['u' => $uuid]) === null) {
            throw new ContractException(404, 'Contract not found.');
        }
        if (trim($reason) === '') {
            throw new ContractException(422, 'A reason is required to void a contract.');
        }
        $this->db->run("UPDATE contracts SET status = 'void', updated_at = :now WHERE uuid = :u", ['now' => Clock::nowIso(), 'u' => $uuid]);
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
        $this->db->run('UPDATE contracts SET supersedes_uuid = :old WHERE uuid = :new', ['old' => $uuid, 'new' => $copy['uuid']]);

        if (in_array((string) $c['status'], ['sent', 'viewed', 'ready', 'signed'], true)) {
            $this->db->run("UPDATE contracts SET status = 'superseded', updated_at = :now WHERE uuid = :u", ['now' => Clock::nowIso(), 'u' => $uuid]);
            $this->event($uuid, 'superseded', 'Superseded: ' . trim($reason), $actor);
        }

        return $this->find((string) $copy['uuid']) ?? [];
    }

    // ==================================================================
    // Document hooks
    // ==================================================================

    /** @return array<string,mixed>|null */
    public function snapshot(string $uuid): ?array
    {
        $raw = (string) $this->db->scalar('SELECT snapshot FROM contracts WHERE uuid = :u', ['u' => $uuid]);
        if ($raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    public function setDocument(string $uuid, string $which, string $key, string $hash, string $status): void
    {
        $col = $which === 'signed' ? 'signed' : 'unsigned';
        $this->db->run(
            "UPDATE contracts SET {$col}_key = :k, {$col}_hash = :h, document_status = :s, updated_at = :n WHERE uuid = :u",
            ['k' => $key, 'h' => $hash, 's' => $status, 'n' => Clock::nowIso(), 'u' => $uuid]
        );
    }

    public function setDocumentStatus(string $uuid, string $status): void
    {
        $this->db->run('UPDATE contracts SET document_status = :s, updated_at = :n WHERE uuid = :u', ['s' => $status, 'n' => Clock::nowIso(), 'u' => $uuid]);
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
        $this->db->run(
            'INSERT INTO contract_parties (uuid, contract_uuid, role, name, email, signing_order, required, status, created_at)
             VALUES (:uuid, :c, :role, :name, :email, :ord, :req, \'pending\', :now)',
            ['uuid' => Uuid::v4(), 'c' => $contractUuid, 'role' => $role, 'name' => $name, 'email' => $email, 'ord' => $order, 'req' => $required ? 1 : 0, 'now' => Clock::nowIso()]
        );
    }

    /**
     * @param array<int,mixed> $sections
     * @return list<array{key:string,heading:string,body:string,optional:bool,enabled:bool}>
     */
    private function normaliseSections(array $sections): array
    {
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

    /** @return list<array{key:string,heading:string,body:string,optional:bool,enabled:bool}> */
    private function decodeSections(string $json): array
    {
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $this->normaliseSections($decoded) : [];
    }

    private function allocateNumber(Database $db, string $prefix, int $year): string
    {
        $db->run(
            'INSERT INTO contract_sequences (prefix, year, next_seq) VALUES (:p, :y, 2)
             ON CONFLICT(prefix, year) DO UPDATE SET next_seq = next_seq + 1',
            ['p' => $prefix, 'y' => $year]
        );
        $seq = (int) $db->scalar('SELECT next_seq - 1 FROM contract_sequences WHERE prefix = :p AND year = :y', ['p' => $prefix, 'y' => $year]);

        return sprintf('%s-%d-%04d', $prefix, $year, $seq);
    }

    /** @param list<string> $allowed */
    private function assertStatus(string $uuid, array $allowed, string $message): void
    {
        $status = (string) $this->db->scalar('SELECT status FROM contracts WHERE uuid = :u', ['u' => $uuid]);
        if ($status === '') {
            throw new ContractException(404, 'Contract not found.');
        }
        if (!in_array($status, $allowed, true)) {
            throw new ContractException(409, $message);
        }
    }

    /** @param list<string> $from */
    private function transition(string $uuid, array $from, string $to, string $eventType, string $detail, string $actor): void
    {
        $this->assertStatus($uuid, $from, 'This contract can’t change to ' . $to . ' from its current state.');
        $this->db->run('UPDATE contracts SET status = :s, updated_at = :now WHERE uuid = :u', ['s' => $to, 'now' => Clock::nowIso(), 'u' => $uuid]);
        $this->event($uuid, $eventType, $detail, $actor);
    }

    private function event(string $uuid, string $type, string $detail, string $actor): void
    {
        $this->db->run(
            'INSERT INTO contract_events (uuid, contract_uuid, type, detail, actor, created_at) VALUES (:uuid, :c, :t, :d, :a, :now)',
            ['uuid' => Uuid::v4(), 'c' => $uuid, 't' => $type, 'd' => $detail, 'a' => $actor, 'now' => Clock::nowIso()]
        );
    }

    private function nullable(mixed $value): ?string
    {
        $v = trim((string) ($value ?? ''));

        return $v === '' ? null : $v;
    }
}
