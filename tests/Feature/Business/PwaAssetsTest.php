<?php

namespace Tests\Feature\Business;

use Tests\TestCase;

/**
 * Step B-7.1 / B-7.3 — the installable-PWA static assets exist and are coherent: the manifest is
 * valid JSON with the icons, scope and (B-7.3) the share target wired to the quick post route, and
 * the service worker + offline shell + icons are present. Agency pages are unaffected because the
 * manifest is only linked client-side inside the business shell (see `PwaProvider`), never in the
 * shared Blade root — asserted here by the root view carrying no manifest link.
 */
class PwaAssetsTest extends TestCase
{
    private function manifest(): array
    {
        $path = public_path('manifest.webmanifest');
        $this->assertFileExists($path);

        return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_manifest_is_valid_and_installable(): void
    {
        $manifest = $this->manifest();

        $this->assertSame('Alima', $manifest['name']);
        $this->assertSame('standalone', $manifest['display']);
        $this->assertSame('/', $manifest['scope']);
        $this->assertNotEmpty($manifest['icons']);

        $purposes = array_column($manifest['icons'], 'purpose');
        $this->assertContains('any', $purposes);
        $this->assertContains('maskable', $purposes);

        foreach ($manifest['icons'] as $icon) {
            $this->assertFileExists(public_path(ltrim($icon['src'], '/')), "Missing icon {$icon['src']}");
        }
    }

    public function test_share_target_points_at_the_quick_post_share_route(): void
    {
        $manifest = $this->manifest();

        $this->assertArrayHasKey('share_target', $manifest);
        $this->assertSame('POST', $manifest['share_target']['method']);
        $this->assertSame('multipart/form-data', $manifest['share_target']['enctype']);
        // The action is the business quick-post share endpoint.
        $this->assertSame(
            parse_url(route('business.posts.quick.share'), PHP_URL_PATH),
            $manifest['share_target']['action'],
        );
        $this->assertSame('media', $manifest['share_target']['params']['files'][0]['name']);
    }

    public function test_service_worker_and_offline_shell_exist(): void
    {
        $this->assertFileExists(public_path('sw.js'));
        $this->assertFileExists(public_path('offline.html'));

        $sw = (string) file_get_contents(public_path('sw.js'));
        // The offline fallback and push handlers are the two reasons the worker exists.
        $this->assertStringContainsString('offline.html', $sw);
        $this->assertStringContainsString("addEventListener('push'", $sw);
    }

    public function test_shared_blade_root_carries_no_manifest_link(): void
    {
        // Agency pages must be untouched — the manifest is injected only in the business shell.
        $blade = (string) file_get_contents(resource_path('views/app.blade.php'));
        $this->assertStringNotContainsString('rel="manifest"', $blade);
    }
}
