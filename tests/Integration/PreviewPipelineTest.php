<?php

declare(strict_types=1);

namespace Breakfast\Tests\Integration;

use Breakfast\Platform\ClientPreviews\PreviewPathGuard;
use Breakfast\Platform\ClientPreviews\PreviewStatus;
use Breakfast\Tests\Support\PlatformTestCase;

/**
 * End-to-end proof of the Client Previews definition of done: an uploaded
 * package is validated, published, served over a custom slug, isolated from the
 * admin, unable to execute server-side code, disable-able, rollback-able and
 * permission/password protected.
 */
final class PreviewPipelineTest extends PlatformTestCase
{
    /** @param array<string,string> $files relative path => contents */
    private function makeZip(array $files): string
    {
        $path = $this->tmpDir . '/upload-' . bin2hex(random_bytes(4)) . '.zip';
        $zip  = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE);
        foreach ($files as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->close();

        return $path;
    }

    public function testCleanUploadPublishesAndServes(): void
    {
        $preview = $this->platform->previews()->create(['name' => 'Acme', 'public_slug' => 'acme']);
        $zip = $this->makeZip([
            'index.html' => '<!doctype html><html><head><title>Acme</title><meta name="viewport" content="width=device-width"></head><body><img src="logo.svg"><link rel="stylesheet" href="css/app.css"></body></html>',
            'css/app.css' => 'body{background:#f5efe3}',
            'logo.svg' => '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script><rect onload="evil()" width="10" height="10"/></svg>',
        ]);

        $result = $this->platform->previewUpload()->uploadZip($preview['uuid'], $zip, 'site.zip', 'studio@example.com');
        $this->assertTrue($result['ok'], 'clean upload should validate: ' . json_encode($result['report']['errors'] ?? []));
        $this->assertSame(PreviewStatus::V_READY, $result['version']['state']);
        $this->assertSame('index.html', $result['version']['entry_file']);

        // Publish → active + current version pointer set.
        $pub = $this->platform->previewPublisher()->publish($preview['uuid'], $result['version']['uuid'], 'studio@example.com');
        $this->assertTrue($pub['ok']);
        $this->assertSame(PreviewStatus::ACTIVE, $pub['preview']['status']);
        $this->assertSame($result['version']['uuid'], $pub['preview']['current_version_uuid']);

        // Serve the entry over the slug.
        $decision = $this->platform->previewAccess()->resolve('acme', '', ['ip' => '203.0.113.5', 'ua' => 'UA']);
        $this->assertSame('file', $decision['type']);
        $this->assertSame(200, $decision['status']);
        $this->assertStringContainsString('text/html', $decision['mime']);
        $this->assertFalse($decision['indexable'], 'unlisted previews are never indexable');

        // The served SVG was sanitised: no script / event handlers on disk.
        $svg = $this->platform->previewAccess()->resolve('acme', 'logo.svg', ['ip' => '203.0.113.5']);
        $this->assertSame('file', $svg['type']);
        $contents = (string) file_get_contents($svg['file']);
        $this->assertStringNotContainsStringIgnoringCase('<script', $contents);
        $this->assertStringNotContainsStringIgnoringCase('onload', $contents);
    }

    public function testRejectsExecutableAndTraversal(): void
    {
        $preview = $this->platform->previews()->create(['name' => 'Evil', 'public_slug' => 'evil']);

        $withPhp = $this->makeZip([
            'index.html' => '<title>x</title>',
            'shell.php'  => '<?php system($_GET["c"]); ?>',
        ]);
        $r1 = $this->platform->previewUpload()->uploadZip($preview['uuid'], $withPhp, 'e.zip', null);
        $this->assertFalse($r1['ok']);
        $this->assertContains('executable_forbidden:shell.php', $r1['report']['errors']);
        $this->assertSame(PreviewStatus::V_VALIDATION_FAILED, $r1['version']['state']);

        // Nothing was published; preview never became active.
        $this->assertSame(PreviewStatus::DRAFT, $this->platform->previews()->find($preview['uuid'])['status']);

        $withTraversal = $this->makeZip([
            'index.html'      => '<title>x</title>',
            '../../etc/pwn.html' => 'x',
        ]);
        $r2 = $this->platform->previewUpload()->uploadZip($preview['uuid'], $withTraversal, 'e2.zip', null);
        $this->assertFalse($r2['ok']);
        $this->assertNotEmpty(array_filter($r2['report']['errors'], fn ($e) => str_starts_with($e, 'unsafe_path')));
    }

    public function testPathGuardBlocksTraversalAtServeTime(): void
    {
        // Even if a request path tries to escape, resolveServed stays contained.
        $dir = $this->tmpDir . '/served';
        @mkdir($dir . '/sub', 0755, true);
        file_put_contents($dir . '/index.html', 'ok');
        file_put_contents($this->tmpDir . '/secret.txt', 'top secret');

        $this->assertNotNull(PreviewPathGuard::resolveServed($dir, 'index.html'));
        $this->assertNull(PreviewPathGuard::resolveServed($dir, '../secret.txt'));
        $this->assertNull(PreviewPathGuard::resolveServed($dir, '..%2f..%2fsecret.txt'));
        $this->assertNull(PreviewPathGuard::resolveServed($dir, '/etc/passwd'));
    }

    public function testDisableServesNeutralPage(): void
    {
        $preview = $this->platform->previews()->create(['name' => 'D', 'public_slug' => 'disme']);
        $zip = $this->makeZip(['index.html' => '<title>x</title>']);
        $r = $this->platform->previewUpload()->uploadZip($preview['uuid'], $zip, 'd.zip', null);
        $this->platform->previewPublisher()->publish($preview['uuid'], $r['version']['uuid'], null);

        $this->platform->previews()->update($preview['uuid'], ['status' => PreviewStatus::DISABLED]);
        $decision = $this->platform->previewAccess()->resolve('disme', '', ['ip' => '203.0.113.9']);
        $this->assertSame('disabled', $decision['type']);
        $this->assertSame(403, $decision['status']);
        $this->assertArrayNotHasKey('file', $decision);
    }

    public function testRollbackToPreviousVersion(): void
    {
        $preview = $this->platform->previews()->create(['name' => 'R', 'public_slug' => 'roll']);
        $v1 = $this->platform->previewUpload()->uploadZip($preview['uuid'], $this->makeZip(['index.html' => '<title>v1</title>']), 'v1.zip', null);
        $this->platform->previewPublisher()->publish($preview['uuid'], $v1['version']['uuid'], null);
        $v2 = $this->platform->previewUpload()->uploadZip($preview['uuid'], $this->makeZip(['index.html' => '<title>v2</title>']), 'v2.zip', null);
        $this->platform->previewPublisher()->publish($preview['uuid'], $v2['version']['uuid'], null);

        $this->assertSame($v2['version']['uuid'], $this->platform->previews()->find($preview['uuid'])['current_version_uuid']);

        $rb = $this->platform->previewPublisher()->rollback($preview['uuid'], $v1['version']['uuid'], null);
        $this->assertTrue($rb['ok']);
        $this->assertSame($v1['version']['uuid'], $rb['preview']['current_version_uuid']);
        // Serving now returns v1's document.
        $decision = $this->platform->previewAccess()->resolve('roll', '', ['ip' => '203.0.113.5']);
        $this->assertStringContainsString('v1', (string) file_get_contents($decision['file']));
    }

    public function testPasswordGate(): void
    {
        $preview = $this->platform->previews()->create(['name' => 'P', 'public_slug' => 'secret-site']);
        $r = $this->platform->previewUpload()->uploadZip($preview['uuid'], $this->makeZip(['index.html' => '<title>x</title>']), 'p.zip', null);
        $this->platform->previewPublisher()->publish($preview['uuid'], $r['version']['uuid'], null);
        $this->platform->previewPasswords()->setPassword($preview['uuid'], 'let-me-in', 'studio@example.com');

        // No token → password gate, not the file.
        $gate = $this->platform->previewAccess()->resolve('secret-site', '', ['ip' => '203.0.113.5']);
        $this->assertSame('password', $gate['type']);

        // Wrong password → generic invalid.
        $fresh = $this->platform->previews()->find($preview['uuid']);
        $bad = $this->platform->previewPasswords()->attempt($fresh, 'nope', '203.0.113.5');
        $this->assertFalse($bad['ok']);
        $this->assertSame('invalid', $bad['error']);

        // Correct → token that unlocks serving.
        $ok = $this->platform->previewPasswords()->attempt($fresh, 'let-me-in', '203.0.113.5');
        $this->assertTrue($ok['ok']);
        $served = $this->platform->previewAccess()->resolve('secret-site', '', ['ip' => '203.0.113.5', 'token' => $ok['token']]);
        $this->assertSame('file', $served['type']);

        // Changing the password invalidates the old token.
        $this->platform->previewPasswords()->setPassword($preview['uuid'], 'new-password', null);
        $after = $this->platform->previewAccess()->resolve('secret-site', '', ['ip' => '203.0.113.5', 'token' => $ok['token']]);
        $this->assertSame('password', $after['type']);
    }
}
