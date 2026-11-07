<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Step GTM-6.1 — the Digital Asset Links file that makes the Play app a *trusted* web activity.
 *
 * The 404-until-configured behaviour is the point of the test: a half-filled file would fail
 * Android's verification silently, and the symptom (an address bar across the top of the app) is
 * easy to misread as a Play problem rather than a deploy problem.
 */
class AssetLinksTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_404s_until_the_package_and_fingerprints_are_configured(): void
    {
        config(['twa.package' => null, 'twa.fingerprints' => []]);
        $this->get('/.well-known/assetlinks.json')->assertNotFound();

        config(['twa.package' => 'com.alima.app', 'twa.fingerprints' => []]);
        $this->get('/.well-known/assetlinks.json')->assertNotFound();
    }

    public function test_it_serves_every_configured_fingerprint(): void
    {
        // Two in practice: Play's app-signing key and the upload key. Both have to be listed or
        // verification fails for whichever build was signed with the missing one.
        config([
            'twa.package' => 'com.alima.app',
            'twa.fingerprints' => ['AA:BB:CC', 'DD:EE:FF'],
        ]);

        $this->get('/.well-known/assetlinks.json')
            ->assertOk()
            ->assertExactJson([[
                'relation' => ['delegate_permission/common.handle_all_urls'],
                'target' => [
                    'namespace' => 'android_app',
                    'package_name' => 'com.alima.app',
                    'sha256_cert_fingerprints' => ['AA:BB:CC', 'DD:EE:FF'],
                ],
            ]]);
    }

    public function test_it_needs_no_session(): void
    {
        config(['twa.package' => 'com.alima.app', 'twa.fingerprints' => ['AA:BB:CC']]);

        // Android fetches it with no cookies; a redirect to login would break verification.
        $this->get('/.well-known/assetlinks.json')->assertOk();
    }
}
