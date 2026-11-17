<?php

namespace Tests\Feature\Mobile;

use Tests\TestCase;

/**
 * Native shell (Capacitor) — the iOS universal-links file is served only once the app id is set,
 * so a half-configured deploy 404s instead of failing Apple's verification quietly.
 */
class AppLinksTest extends TestCase
{
    public function test_the_apple_app_site_association_404s_until_configured(): void
    {
        config(['twa.ios_app_id' => null]);

        $this->get('/.well-known/apple-app-site-association')->assertNotFound();
    }

    public function test_it_names_the_app_for_every_path(): void
    {
        config(['twa.ios_app_id' => 'ABCDE12345.com.alima.app']);

        $this->get('/.well-known/apple-app-site-association')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/json')
            ->assertExactJson([
                'applinks' => ['details' => [[
                    'appIDs' => ['ABCDE12345.com.alima.app'],
                    'components' => [['/' => '/*']],
                ]]],
            ]);
    }
}
