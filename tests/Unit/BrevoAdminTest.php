<?php

declare(strict_types=1);

namespace Breakfast\Tests\Unit;

use Breakfast\Platform\Mail\BrevoAdmin;
use Breakfast\Platform\Mail\HttpClient;
use Breakfast\Platform\Mail\HttpResponse;
use PHPUnit\Framework\TestCase;

final class BrevoAdminTest extends TestCase
{
    protected function tearDown(): void
    {
        HttpClient::useTransport(null);
    }

    public function testSyncContactDryRunMapsApprovedFieldsOnly(): void
    {
        $admin = new BrevoAdmin(['apiKey' => 'k']);
        $res = $admin->syncContact([
            'uuid' => 'u1', 'email' => 'a@b.com', 'first_name' => 'Sam', 'last_name' => 'Rivers',
            'company_name' => 'Rivers Ltd', 'notes' => 'SECRET NOTE', 'estimated_value' => 5000,
        ], null, true);

        $this->assertTrue($res['dryRun']);
        $this->assertSame('a@b.com', $res['payload']['email']);
        $this->assertSame('u1', $res['payload']['ext_id']);
        $this->assertSame('Sam', $res['payload']['attributes']['FIRSTNAME']);
        // Private data must never be mapped.
        $json = json_encode($res['payload']);
        $this->assertStringNotContainsString('SECRET NOTE', (string) $json);
        $this->assertStringNotContainsString('5000', (string) $json);
        $this->assertArrayNotHasKey('listIds', $res['payload'], 'no marketing list without a list id + consent');
    }

    public function testSyncInvalidEmailRejected(): void
    {
        $res = (new BrevoAdmin(['apiKey' => 'k']))->syncContact(['uuid' => 'u', 'email' => 'bad'], null, true);
        $this->assertFalse($res['ok']);
        $this->assertSame('invalid_email', $res['detail']);
    }

    public function testWebhookRegisterIsIdempotent(): void
    {
        HttpClient::useTransport(function (string $m, string $u) {
            if ($m === 'GET') {
                return new HttpResponse(200, json_encode(['webhooks' => [['id' => 5, 'url' => 'https://x/hook']]]));
            }
            return new HttpResponse(201, json_encode(['id' => 9]));
        });
        $res = (new BrevoAdmin(['apiKey' => 'k']))->webhookRegister('https://x/hook', ['delivered']);
        $this->assertFalse($res['created'], 'existing webhook is reused, not duplicated');
        $this->assertSame(5, $res['id']);
    }
}
