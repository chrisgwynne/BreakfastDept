<?php

declare(strict_types=1);

namespace Breakfast\Platform\Mail;

/**
 * Brevo account administration used by the CLI: webhook inspection/registration
 * and contact sync. All calls are bounded and guarded; destructive/mutating
 * actions are only invoked from `bin/console` behind explicit --confirm flags.
 * Never logs the API key.
 */
final class BrevoAdmin
{
    /**
     * @param array{apiKey:string,baseUrl?:string} $config
     */
    public function __construct(
        private readonly array $config,
        private readonly HttpClient $http = new HttpClient(),
    ) {
    }

    public function configured(): bool
    {
        return ($this->config['apiKey'] ?? '') !== '';
    }

    /**
     * List transactional webhooks.
     *
     * @return array{ok:bool,webhooks:list<array<string,mixed>>,detail:string}
     */
    public function webhookStatus(): array
    {
        $res = $this->get('/webhooks?type=transactional');
        if ($res->ok() === false) {
            return ['ok' => false, 'webhooks' => [], 'detail' => 'HTTP ' . $res->status];
        }
        $body = $res->json();
        $hooks = is_array($body['webhooks'] ?? null) ? $body['webhooks'] : [];

        return ['ok' => true, 'webhooks' => array_values($hooks), 'detail' => count($hooks) . ' webhook(s)'];
    }

    /**
     * Register a transactional webhook idempotently — if one already targets the
     * same URL, it is reused rather than duplicated.
     *
     * @param list<string> $events
     * @return array{ok:bool,id:?int,created:bool,detail:string}
     */
    public function webhookRegister(string $url, array $events): array
    {
        foreach ($this->webhookStatus()['webhooks'] as $hook) {
            if (($hook['url'] ?? null) === $url) {
                return ['ok' => true, 'id' => (int) ($hook['id'] ?? 0), 'created' => false, 'detail' => 'already registered'];
            }
        }

        $res = $this->post('/webhooks', [
            'type'        => 'transactional',
            'url'         => $url,
            'events'      => $events,
            'description' => 'Breakfast transactional events',
        ]);

        if ($res->ok() === false) {
            return ['ok' => false, 'id' => null, 'created' => false, 'detail' => 'HTTP ' . $res->status];
        }

        return ['ok' => true, 'id' => (int) ($res->json()['id'] ?? 0), 'created' => true, 'detail' => 'created'];
    }

    /** @return array{ok:bool,detail:string} */
    public function webhookRemove(int $id): array
    {
        $res = $this->http->request('DELETE', $this->url('/webhooks/' . $id), $this->headers());

        return ['ok' => $res->ok() || $res->status === 204, 'detail' => 'HTTP ' . $res->status];
    }

    /** @return array{ok:bool,detail:string} */
    public function accountCheck(): array
    {
        $res = $this->get('/account');

        return ['ok' => $res->ok(), 'detail' => $res->ok() ? 'authenticated' : 'HTTP ' . $res->status];
    }

    /**
     * List verified senders. Used by the Settings connection test to confirm the
     * configured "from" address can actually send.
     *
     * @return array{ok:bool,detail:string,senders:list<string>}
     */
    public function sendersCheck(): array
    {
        $res = $this->get('/senders');
        if ($res->ok() === false) {
            return ['ok' => false, 'detail' => 'HTTP ' . $res->status, 'senders' => []];
        }
        $body    = $res->json();
        $senders = [];
        foreach (is_array($body['senders'] ?? null) ? $body['senders'] : [] as $s) {
            $email = strtolower(trim((string) ($s['email'] ?? '')));
            if ($email !== '') {
                $senders[] = $email;
            }
        }

        return ['ok' => true, 'detail' => count($senders) . ' sender(s)', 'senders' => $senders];
    }

    /**
     * Upsert one contact into Brevo. Maps only approved, non-sensitive fields and
     * uses the CRM UUID as the external identifier. Never sends notes, opportunity
     * values, uploads or Hermes classifications.
     *
     * @param array<string,mixed> $contact CRM contact row
     * @param bool $dryRun when true, returns the mapped payload without sending
     * @return array{ok:bool,dryRun:bool,payload:array<string,mixed>,detail:string}
     */
    public function syncContact(array $contact, ?string $listId, bool $dryRun): array
    {
        $email = is_string($contact['email'] ?? null) ? (string) $contact['email'] : '';
        if (EmailAddress::isValid($email) === false) {
            return ['ok' => false, 'dryRun' => $dryRun, 'payload' => [], 'detail' => 'invalid_email'];
        }

        // Approved fields only.
        $payload = [
            'email'         => strtolower($email),
            'ext_id'        => (string) $contact['uuid'],
            'updateEnabled' => true,
            'attributes'    => array_filter([
                'FIRSTNAME' => $contact['first_name'] ?? null,
                'LASTNAME'  => $contact['last_name'] ?? null,
                'COMPANY'   => $contact['company_name'] ?? null,
            ], static fn ($v): bool => $v !== null && $v !== ''),
        ];

        // Marketing-list membership only when a list id is supplied AND consent
        // is granted (checked by the caller).
        if ($listId !== null && $listId !== '') {
            $payload['listIds'] = [(int) $listId];
        }

        if ($dryRun) {
            return ['ok' => true, 'dryRun' => true, 'payload' => $payload, 'detail' => 'dry-run'];
        }

        $res = $this->post('/contacts', $payload);

        return ['ok' => $res->ok() || $res->status === 204, 'dryRun' => false, 'payload' => $payload, 'detail' => 'HTTP ' . $res->status];
    }

    private function get(string $path): HttpResponse
    {
        return $this->http->request('GET', $this->url($path), $this->headers());
    }

    /** @param array<string,mixed> $body */
    private function post(string $path, array $body): HttpResponse
    {
        return $this->http->request('POST', $this->url($path), $this->headers(), json_encode($body, JSON_UNESCAPED_SLASHES) ?: '{}');
    }

    private function url(string $path): string
    {
        return rtrim((string) ($this->config['baseUrl'] ?? 'https://api.brevo.com/v3'), '/') . $path;
    }

    /** @return array<string,string> */
    private function headers(): array
    {
        return ['accept' => 'application/json', 'content-type' => 'application/json', 'api-key' => (string) $this->config['apiKey']];
    }
}
