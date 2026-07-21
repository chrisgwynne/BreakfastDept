<?php

declare(strict_types=1);

namespace Breakfast\Tests\Integration;

use Breakfast\Platform\Hermes\Api;
use Breakfast\Platform\Hermes\AuditLog;
use Breakfast\Platform\Hermes\Authenticator;
use Breakfast\Platform\Hermes\Credential;
use Breakfast\Platform\Hermes\CredentialStore;
use Breakfast\Platform\Hermes\Scopes;
use Breakfast\Platform\Hermes\Signer;
use Breakfast\Platform\Support\Clock;
use Breakfast\Tests\Support\PlatformTestCase;

final class HermesApiTest extends PlatformTestCase
{
    private string $secret = 'super-secret-key';

    private function api(array $scopes): Api
    {
        $store = new CredentialStore([
            'HERMES_KEY_test' => implode(',', $scopes) . '|' . base64_encode($this->secret),
        ]);
        $auth = new Authenticator($store, $this->platform->db(), 300);

        return new Api($this->platform, $auth, new AuditLog($this->platform->db()));
    }

    /** @return array<string,string> */
    private function signedHeaders(string $method, string $path, string $body, ?string $nonce = null, ?int $ts = null): array
    {
        $ts    = $ts ?? Clock::timestamp();
        $nonce = $nonce ?? bin2hex(random_bytes(8));
        $canonical = Signer::canonical($method, $path, (string) $ts, $nonce, $body);

        return [
            'x-hermes-key'       => 'test',
            'x-hermes-timestamp' => (string) $ts,
            'x-hermes-nonce'     => $nonce,
            'x-hermes-signature' => Signer::sign($this->secret, $canonical),
        ];
    }

    public function testHealthNeedsNoAuth(): void
    {
        $res = $this->api([Scopes::CRM_SUMMARY])->handle('GET', '/health', '', []);
        $this->assertSame(200, $res['status']);
        $this->assertSame('ok', $res['body']['status']);
    }

    public function testValidSignedRequestSucceeds(): void
    {
        $headers = $this->signedHeaders('GET', '/crm/summary', '');
        $res = $this->api([Scopes::CRM_SUMMARY])->handle('GET', '/crm/summary', '', $headers);
        $this->assertSame(200, $res['status']);
        $this->assertArrayHasKey('new_enquiries', $res['body']);
    }

    public function testBadSignatureRejected(): void
    {
        $headers = $this->signedHeaders('GET', '/crm/summary', '');
        $headers['x-hermes-signature'] = 'deadbeef';
        $res = $this->api([Scopes::CRM_SUMMARY])->handle('GET', '/crm/summary', '', $headers);
        $this->assertSame(401, $res['status']);
    }

    public function testReplayedNonceRejected(): void
    {
        $api = $this->api([Scopes::CRM_SUMMARY]);
        $headers = $this->signedHeaders('GET', '/crm/summary', '', 'fixed-nonce');
        $first = $api->handle('GET', '/crm/summary', '', $headers);
        $second = $api->handle('GET', '/crm/summary', '', $headers);
        $this->assertSame(200, $first['status']);
        $this->assertSame(401, $second['status'], 'the same nonce cannot be used twice');
    }

    public function testExpiredTimestampRejected(): void
    {
        $headers = $this->signedHeaders('GET', '/crm/summary', '', null, Clock::timestamp() - 10000);
        $res = $this->api([Scopes::CRM_SUMMARY])->handle('GET', '/crm/summary', '', $headers);
        $this->assertSame(401, $res['status']);
    }

    public function testScopeDenied(): void
    {
        // Credential only has summary scope; /crm/enquiries needs crm:read.
        $headers = $this->signedHeaders('GET', '/crm/enquiries', '');
        $res = $this->api([Scopes::CRM_SUMMARY])->handle('GET', '/crm/enquiries', '', $headers);
        $this->assertSame(403, $res['status']);
        $this->assertSame(Scopes::CRM_READ, $res['body']['required_scope']);
    }

    public function testNoteRequiresCrmNotesScopeAndCreatesActivity(): void
    {
        // Seed an enquiry to annotate.
        $enquiry = $this->platform->enquiries()->create(['form_type' => 'contact', 'payload' => []]);
        $path = '/crm/enquiries/' . $enquiry['uuid'] . '/note';
        $body = json_encode(['note' => 'Looks like a strong lead']);

        $headers = $this->signedHeaders('POST', $path, $body);
        $res = $this->api([Scopes::CRM_NOTES])->handle('POST', $path, $body, $headers);

        $this->assertSame(201, $res['status']);
        $activity = $this->platform->activities()->forEntity('enquiry', $enquiry['uuid']);
        $this->assertContains('note.added', array_column($activity, 'type'));
        // Actor is recorded as hermes.
        $this->assertContains('hermes', array_column($activity, 'actor_type'));
    }

    public function testAuditTrailWritten(): void
    {
        $headers = $this->signedHeaders('GET', '/crm/summary', '');
        $this->api([Scopes::CRM_SUMMARY])->handle('GET', '/crm/summary', '', $headers);

        $audit = (new AuditLog($this->platform->db()))->recent();
        $this->assertNotEmpty($audit);
        $this->assertSame('test', $audit[0]['credential_id']);
        $this->assertSame('ok', $audit[0]['result']);
    }

    public function testUnknownCredentialRejected(): void
    {
        $res = $this->api([Scopes::CRM_SUMMARY])->handle('GET', '/crm/summary', '', [
            'x-hermes-key' => 'ghost', 'x-hermes-timestamp' => (string) Clock::timestamp(),
            'x-hermes-nonce' => 'n', 'x-hermes-signature' => 'x',
        ]);
        $this->assertSame(401, $res['status']);
    }

    public function testPreviewsDraftCreatesUnpublishedDraft(): void
    {
        $body    = json_encode(['name' => 'Acme mock', 'slug' => 'acme-mock']) ?: '';
        $headers = $this->signedHeaders('POST', '/previews/draft', $body);
        $res     = $this->api([Scopes::PREVIEWS_DRAFT])->handle('POST', '/previews/draft', $body, $headers);

        $this->assertSame(201, $res['status']);
        $this->assertSame('draft', $res['body']['preview']['status'], 'Hermes previews are never live');

        // It really is an unpublished draft with no live version.
        $row = $this->platform->previews()->findBySlug('acme-mock');
        $this->assertNotNull($row);
        $this->assertSame('draft', $row['status']);
        $this->assertNull($row['current_version_uuid']);
    }

    public function testPreviewsReadRequiresItsScope(): void
    {
        $headers = $this->signedHeaders('GET', '/previews', '');
        // A token with only the draft scope may not read the list.
        $res = $this->api([Scopes::PREVIEWS_DRAFT])->handle('GET', '/previews', '', $headers);
        $this->assertSame(403, $res['status']);
        $this->assertSame('previews:read', $res['body']['required_scope']);
    }

    public function testHermesHasNoPublishOrUploadEndpoint(): void
    {
        // There is no route for publishing/uploading — Hermes can never make a
        // preview live, whatever scope it holds.
        foreach (['/previews/anything/publish', '/previews/anything/upload'] as $path) {
            $headers = $this->signedHeaders('POST', $path, '');
            $res = $this->api(Scopes::all())->handle('POST', $path, '', $headers);
            $this->assertSame(404, $res['status'], $path . ' must not exist');
        }
    }
}
