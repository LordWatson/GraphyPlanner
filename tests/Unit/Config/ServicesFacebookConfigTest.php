<?php

namespace Tests\Unit\Config;

use Tests\TestCase;

/**
 * Step 1.9.1 — `services.facebook` must resolve with safe defaults even when none of the
 * FACEBOOK_* env vars are set, so the app boots without crashing before the Facebook App is
 * actually registered.
 */
class ServicesFacebookConfigTest extends TestCase
{
    public function test_services_facebook_config_resolves_with_sane_defaults_when_env_vars_are_unset(): void
    {
        config([
            'services.facebook.client_id' => null,
            'services.facebook.client_secret' => null,
            'services.facebook.redirect_uri' => null,
        ]);

        $this->assertNull(config('services.facebook.client_id'));
        $this->assertNull(config('services.facebook.client_secret'));
        $this->assertNull(config('services.facebook.redirect_uri'));
        $this->assertSame('https://graph.facebook.com', config('services.facebook.graph_base_url'));
        $this->assertNotEmpty(config('services.facebook.graph_version'));
    }
}
