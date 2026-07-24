<?php

declare(strict_types=1);

namespace Breakfast\Tests\Integration;

use Breakfast\Platform\Support\Database;
use Breakfast\Platform\Support\Platform;
use Breakfast\Platform\Website\WebsiteContent;
use Breakfast\Platform\Website\WebsiteException;
use Kirby\Cms\App;
use Kirby\Content\VersionId;
use PHPUnit\Framework\TestCase;

/**
 * Real website editing over the genuine Kirby content model — the feature that
 * makes "edit the homepage from the admin, save a draft, preview, publish"
 * actually work. Proves: drafts write to the `changes` version only (never the
 * live file), publish moves the draft to live while preserving untouched fields,
 * preview renders the draft without persisting, discard removes it, validation
 * rejects bad input, and unsupported field types are surfaced read-only rather
 * than as fake editable controls.
 */
final class WebsiteContentTest extends TestCase
{
    private App $kirby;
    private string $tmp;
    private WebsiteContent $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $base = dirname(__DIR__, 2);
        $this->tmp = sys_get_temp_dir() . '/bf-web-' . bin2hex(random_bytes(6));
        @mkdir($this->tmp . '/database', 0777, true);
        @mkdir($this->tmp . '/content/home', 0777, true);
        @mkdir($this->tmp . '/site/blueprints/pages', 0777, true);
        @mkdir($this->tmp . '/site/templates', 0777, true);
        // Load the real platform plugin (registers breakfast() + services) while
        // keeping this controlled temp blueprint/template/content.
        @symlink($base . '/site/plugins', $this->tmp . '/site/plugins');

        // A representative page: editable scalars + a required + a maxlength SEO
        // field + a complex (structure) field that must stay read-only.
        file_put_contents(
            $this->tmp . '/content/home/home.txt',
            "Title: Home\n\n----\n\nHeadline: Original headline\n\n----\n\n"
            . "Intro: Original intro\n\n----\n\nShow_banner: true\n\n----\n\n"
            . "Seo_title: Fine title\n\n----\n\nKeepme: untouched value\n"
        );
        file_put_contents(
            $this->tmp . '/site/blueprints/pages/home.yml',
            "title: Home\nfields:\n"
            . "  headline: { type: text, required: true }\n"
            . "  intro: { type: textarea }\n"
            . "  show_banner: { type: toggle }\n"
            . "  seo_title: { type: text, maxlength: 20 }\n"
            . "  keepme: { type: text }\n"
            . "  blocks: { type: structure, fields: { t: { type: text } } }\n"
        );
        file_put_contents(
            $this->tmp . '/site/templates/home.php',
            'H:<?= $page->headline() ?>|I:<?= $page->intro() ?>'
        );
        file_put_contents($this->tmp . '/content/site.txt', "Title: Breakfast\n\n----\n\nTagline: Original tagline\n");
        file_put_contents(
            $this->tmp . '/site/blueprints/site.yml',
            "title: Site\nfields:\n  tagline: { type: text }\n"
        );

        Database::reset();
        Platform::reset();
        // Kirby caches blueprint definitions statically by name; clear it so this
        // temp site's blueprints aren't shadowed by another test's real ones.
        \Kirby\Cms\Blueprint::$loaded = [];

        $this->kirby = new App([
            'roots' => [
                'index'    => $this->tmp . '/public',
                'base'     => $this->tmp,
                'site'     => $this->tmp . '/site',
                'content'  => $this->tmp . '/content',
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
        $this->kirby->impersonate('kirby');
        breakfast()->migrator()->migrate();
        $this->svc = new WebsiteContent($this->kirby, breakfast());
    }

    protected function tearDown(): void
    {
        Database::reset();
        Platform::reset();
        \Kirby\Cms\Blueprint::$loaded = [];
        $this->rrmdir($this->tmp);
        App::destroy();
        restore_error_handler();
        restore_exception_handler();
        parent::tearDown();
    }

    public function testIndexListsSiteAndPages(): void
    {
        $index = $this->svc->index();
        $ids   = array_column($index['items'], 'id');
        $this->assertContains('site', $ids);
        $this->assertContains('home', $ids);
    }

    public function testLoadSeparatesEditableFromReadOnlyFields(): void
    {
        $ed = $this->svc->load('home');
        $editable = array_column($ed['fields'], 'name');
        $readonly = array_column($ed['readonly'], 'name');

        $this->assertContains('headline', $editable);
        $this->assertContains('intro', $editable);
        $this->assertContains('show_banner', $editable);
        $this->assertContains('blocks', $readonly, 'structure fields must be read-only, never fake-editable');

        $headline = $this->field($ed['fields'], 'headline');
        $this->assertSame('Original headline', $headline['value']);
        $this->assertTrue($headline['required']);
        $this->assertTrue($this->field($ed['fields'], 'show_banner')['value']);
    }

    public function testSaveWritesDraftOnlyAndLeavesLiveUntouched(): void
    {
        $ed = $this->svc->save('home', ['headline' => 'Draft headline'], 'editor@test');

        $this->assertSame('modified', $ed['status']);
        $this->assertTrue($ed['has_changes']);
        $this->assertSame('Draft headline', $this->field($ed['fields'], 'headline')['value']);

        // The LIVE (published) content is unchanged — the draft lives only in the
        // changes version. (Kirby may normalise key formatting on write, so we
        // compare the semantic value, not raw bytes.)
        $live = $this->kirby->page('home')->version(VersionId::latest())->read('default');
        $this->assertSame('Original headline', $live['headline']);

        // The audit trail recorded the save.
        $this->assertNotEmpty(breakfast()->audit()->recent(5));
    }

    public function testPublishAppliesDraftToLiveAndPreservesUntouchedFields(): void
    {
        $this->svc->save('home', ['headline' => 'Published headline'], 'editor@test');
        $ed = $this->svc->publish('home', 'editor@test');

        // No draft remains after publishing (status reflects the page's listing,
        // which for this unprefixed fixture page is 'unlisted' — the point is it
        // is no longer 'modified').
        $this->assertNotSame('modified', $ed['status']);
        $this->assertFalse($ed['has_changes']);

        // Live content now reflects the edit, and the field we never touched survives.
        $page = $this->kirby->page('home')->version(VersionId::latest())->read('default');
        $this->assertSame('Published headline', $page['headline']);
        $this->assertSame('untouched value', $page['keepme']);
    }

    public function testDiscardRemovesTheDraft(): void
    {
        $this->svc->save('home', ['headline' => 'Discard me'], 'editor@test');
        $ed = $this->svc->discard('home', 'editor@test');

        $this->assertFalse($ed['has_changes']);
        // Live value is still the original.
        $this->assertSame('Original headline', $this->field($ed['fields'], 'headline')['value']);
    }

    public function testPreviewRendersDraftWithoutPersisting(): void
    {
        $this->svc->save('home', ['headline' => 'PREVIEW ONLY'], 'editor@test');
        $html = $this->svc->previewHtml('home');

        $this->assertStringContainsString('PREVIEW ONLY', $html);
        // Not written to the live file.
        $this->assertStringNotContainsString('PREVIEW ONLY', (string) file_get_contents($this->tmp . '/content/home/home.txt'));
    }

    public function testRequiredFieldIsValidatedServerSide(): void
    {
        try {
            $this->svc->save('home', ['headline' => ''], 'editor@test');
            $this->fail('Expected a validation error');
        } catch (WebsiteException $e) {
            $this->assertSame(422, $e->status);
            $this->assertArrayHasKey('headline', $e->fields);
        }
    }

    public function testMaxlengthIsEnforcedSoTheEditorCannotIntroduceViolations(): void
    {
        try {
            $this->svc->save('home', ['seo_title' => str_repeat('x', 40)], 'editor@test');
            $this->fail('Expected a maxlength error');
        } catch (WebsiteException $e) {
            $this->assertSame(422, $e->status);
            $this->assertArrayHasKey('seo_title', $e->fields);
        }
    }

    public function testPublishWithoutChangesIsRejected(): void
    {
        try {
            $this->svc->publish('home', 'editor@test');
            $this->fail('Expected a 409');
        } catch (WebsiteException $e) {
            $this->assertSame(409, $e->status);
        }
    }

    public function testUnknownPageIs404(): void
    {
        try {
            $this->svc->load('does-not-exist');
            $this->fail('Expected a 404');
        } catch (WebsiteException $e) {
            $this->assertSame(404, $e->status);
        }
    }

    public function testGlobalSiteSettingsAreEditable(): void
    {
        $ed = $this->svc->load('site');
        $this->assertSame('site', $ed['kind']);
        $this->assertNotEmpty($ed['fields']);
    }

    /**
     * @param list<array<string,mixed>> $fields
     * @return array<string,mixed>
     */
    private function field(array $fields, string $name): array
    {
        foreach ($fields as $f) {
            if (($f['name'] ?? '') === $name) {
                return $f;
            }
        }
        $this->fail("Field {$name} not found");
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
            // Never recurse into a symlink — unlink the link itself so we can't
            // delete the real target it points at (e.g. the plugin directory).
            if (is_link($path)) {
                @unlink($path);
                continue;
            }
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
