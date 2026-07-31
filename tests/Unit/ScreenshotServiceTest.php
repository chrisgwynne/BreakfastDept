<?php

declare(strict_types=1);

namespace Breakfast\Tests\Unit;

use Breakfast\Platform\Hermes\AuditLog;
use Breakfast\Platform\Screenshots\CaptureRequest;
use Breakfast\Platform\Screenshots\NullCaptureDriver;
use Breakfast\Platform\Screenshots\ScreenshotService;
use Breakfast\Platform\Support\FileStore;
use PHPUnit\Framework\TestCase;

final class ScreenshotServiceTest extends TestCase
{
    private string $tmp;
    private ScreenshotService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir() . '/bf-shots-' . bin2hex(random_bytes(6));
        @mkdir($this->tmp, 0777, true);
        $store = new FileStore($this->tmp);
        $this->svc = new ScreenshotService($store, new NullCaptureDriver(), new AuditLog($store), $this->tmp);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp . '/*/*/*') ?: [] as $f) {
            @is_file($f) && @unlink($f);
        }
        parent::tearDown();
    }

    private function req(string $url = 'https://acme.example/'): CaptureRequest
    {
        return new CaptureRequest($url, 'homepage', 'desktop');
    }

    public function testCaptureRejectsUrlOutsideAllowlist(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('url_rejected:host_not_approved');
        $this->svc->capture('proj-1', ['acme.example'], $this->req('https://someone-else.com/'), 'chris');
    }

    public function testCaptureRejectsPrivateAddress(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('url_rejected:private_address_blocked');
        $this->svc->capture('proj-1', ['127.0.0.1'], $this->req('https://127.0.0.1/'), 'chris');
    }

    public function testCaptureStoresRecordAndBytesButNotPublishable(): void
    {
        // acme.example resolves to nothing in test → use a literal public IP host.
        $rec = $this->svc->capture('proj-1', ['93.184.216.34'], $this->req('https://93.184.216.34/'), 'chris');

        $this->assertSame('proj-1', $rec['project_uuid']);
        $this->assertSame(1, $rec['version']);
        $this->assertTrue($rec['is_stub']);                       // NullDriver
        $this->assertFalse($rec['public_use_approved']);
        $this->assertFalse($rec['privacy_reviewed']);
        $this->assertFileExists($this->tmp . '/' . $rec['file']);
        $this->assertFalse($this->svc->publishable($rec), 'fresh capture must not be publishable');
    }

    public function testPublishableRequiresBothApprovalAndPrivacyReview(): void
    {
        $rec = $this->svc->capture('proj-1', ['93.184.216.34'], $this->req('https://93.184.216.34/'), 'chris');
        $id = $rec['uuid'];

        $afterApprove = $this->svc->approvePublicUse($id, 'chris');
        $this->assertNotNull($afterApprove);
        $this->assertFalse($this->svc->publishable($afterApprove), 'approval alone is not enough');

        $afterPrivacy = $this->svc->markPrivacyReviewed($id, 'chris', 'checked — nothing personal on screen');
        $this->assertNotNull($afterPrivacy);
        $this->assertTrue($this->svc->publishable($afterPrivacy), 'both gates cleared → publishable');
    }

    public function testRecaptureVersionsAndSupersedes(): void
    {
        $v1 = $this->svc->capture('proj-1', ['93.184.216.34'], $this->req('https://93.184.216.34/'), 'chris');
        $v2 = $this->svc->recapture($v1['uuid'], $this->req('https://93.184.216.34/'), ['93.184.216.34'], 'chris');

        $this->assertSame(2, $v2['version']);
        $this->assertSame($v1['uuid'], $v2['replaces']);
        $this->assertSame('superseded', $this->svc->get($v1['uuid'])['status'], 'previous version retained, not overwritten');

        $history = $this->svc->history($v2['uuid']);
        $this->assertCount(2, $history);
        $this->assertSame($v2['uuid'], $history[0]['uuid']);
        $this->assertSame($v1['uuid'], $history[1]['uuid']);
    }

    public function testManualUploadRejectsNonImage(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('invalid_image');
        $this->svc->uploadManual('proj-1', 'this is not an image', 'homepage', 'chris');
    }

    public function testListExcludesArchivedByDefault(): void
    {
        $a = $this->svc->capture('proj-2', ['93.184.216.34'], $this->req('https://93.184.216.34/a'), 'chris');
        $this->svc->capture('proj-2', ['93.184.216.34'], $this->req('https://93.184.216.34/b'), 'chris');
        $this->svc->archive($a['uuid'], 'chris');

        $this->assertCount(1, $this->svc->list('proj-2'));
        $this->assertCount(2, $this->svc->list('proj-2', true));
    }
}
