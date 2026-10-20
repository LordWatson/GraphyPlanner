<?php

namespace Tests\Feature\Security;

use App\Contracts\VirusScanner;
use App\Enums\Role;
use App\Models\Client;
use App\Models\Organization;
use App\Models\User;
use App\Rules\SafeUpload;
use App\Services\Security\ClamAvVirusScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Vite;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Step I-16.2 / M-1.2 — security headers on every response, password confirmation for sensitive
 * Owner actions, and upload hardening (content sniffing, no SVG, virus-scan hook).
 */
class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $owner;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();
        $this->owner = User::factory()->for($this->org, 'organization')->role(Role::Owner)->create();
        $this->client = Client::factory()->for($this->org, 'organization')->create();
    }

    // --- Headers ---------------------------------------------------------------------------

    public function test_every_kind_of_response_carries_the_security_headers(): void
    {
        $responses = [
            'public page' => $this->get('/'),
            'redirect' => $this->get('/dashboard'),
            'not found' => $this->get('/definitely-not-a-page'),
            'json webhook' => $this->postJson('/webhooks/upload-post', ['event' => 'social_account_connected']),
            'signed-in page' => $this->actingAs($this->owner)->get(route('clients.index')),
        ];

        foreach ($responses as $label => $response) {
            $this->assertSecurityHeaders($response, $label);
        }
    }

    public function test_the_csp_nonce_matches_the_inline_and_vite_scripts(): void
    {
        $response = $this->get('/')->assertOk();
        $csp = (string) $response->headers->get('Content-Security-Policy');

        $this->assertMatchesRegularExpression("/script-src 'self' 'nonce-([A-Za-z0-9]+)'/", $csp);
        preg_match("/'nonce-([A-Za-z0-9]+)'/", $csp, $match);

        $this->assertStringContainsString('<script nonce="'.$match[1].'"', (string) $response->getContent());
        $this->assertStringNotContainsString("'unsafe-inline' 'nonce", $csp);
        $this->assertStringContainsString("frame-ancestors 'self'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
    }

    /**
     * Regression: browsers ignore IPv6-literal CSP sources, so a Vite dev server on `[::1]`
     * had every module blocked and the app rendered unstyled and dead.
     */
    public function test_the_vite_dev_server_is_allowed_including_on_an_ipv6_host(): void
    {
        $hot = tempnam(sys_get_temp_dir(), 'hot');
        Vite::useHotFile((string) $hot);

        file_put_contents((string) $hot, 'http://[::1]:5173');
        $csp = (string) $this->get('/')->headers->get('Content-Security-Policy');
        $this->assertStringNotContainsString('[::1]', $csp);
        $this->assertMatchesRegularExpression("/script-src 'self' 'nonce-[A-Za-z0-9]+' http:/", $csp);
        $this->assertStringContainsString('ws:', $csp);

        file_put_contents((string) $hot, 'http://127.0.0.1:5173');
        $csp = (string) $this->get('/')->headers->get('Content-Security-Policy');
        $this->assertStringContainsString('http://127.0.0.1:5173 http://localhost:5173', $csp);
        $this->assertStringContainsString('ws://localhost:5173', $csp);

        @unlink((string) $hot);
    }

    public function test_hsts_is_only_sent_over_https(): void
    {
        $this->get('/')->assertHeaderMissing('Strict-Transport-Security');

        $this->get('https://localhost/')
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }

    public function test_csp_can_run_report_only_and_skips_the_internal_dashboards(): void
    {
        config(['security.csp.report_only' => true]);

        $this->get('/')
            ->assertHeaderMissing('Content-Security-Policy')
            ->assertHeader('Content-Security-Policy-Report-Only');

        $this->get('/horizon')->assertHeaderMissing('Content-Security-Policy-Report-Only');
    }

    // --- Password confirmation -------------------------------------------------------------

    public function test_organization_settings_require_a_recent_password_confirmation(): void
    {
        $this->actingAs($this->owner)
            ->get(route('organization.edit'))
            ->assertRedirect(route('password.confirm'));

        $this->actingAs($this->owner)
            ->put(route('organization.update'), ['name' => 'Renamed'])
            ->assertRedirect(route('password.confirm'));
        $this->assertNotSame('Renamed', $this->org->refresh()->name);

        $this->actingAs($this->owner)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('organization.edit'))
            ->assertOk();
    }

    public function test_data_exports_require_a_recent_password_confirmation(): void
    {
        $this->actingAs($this->owner)
            ->get(route('invoices.export'))
            ->assertRedirect(route('password.confirm'));

        $this->actingAs($this->owner)
            ->get(route('analytics.export'))
            ->assertRedirect(route('password.confirm'));

        $this->actingAs($this->owner)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('invoices.export'))
            ->assertOk();
    }

    public function test_purging_a_client_needs_the_owners_current_password(): void
    {
        $this->client->delete();

        $this->actingAs($this->owner)
            ->delete(route('clients.purge', $this->client))
            ->assertSessionHasErrors('password');

        $this->actingAs($this->owner)
            ->delete(route('clients.purge', $this->client), ['password' => 'not-my-password'])
            ->assertSessionHasErrors('password');

        $this->assertNotNull(Client::withTrashed()->find($this->client->id));

        $this->actingAs($this->owner)
            ->delete(route('clients.purge', $this->client), ['password' => 'password'])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertNull(Client::withTrashed()->find($this->client->id));
    }

    public function test_deleting_an_organization_needs_the_super_admins_current_password(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)
            ->delete(route('super-admin.organizations.destroy', $this->org), ['confirm_name' => $this->org->name])
            ->assertSessionHasErrors('password');

        $this->assertNotNull(Organization::find($this->org->id));
    }

    // --- Uploads ---------------------------------------------------------------------------

    public function test_svg_uploads_are_refused(): void
    {
        Storage::fake('local');
        $svg = $this->realFile('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');

        $this->actingAs($this->owner)
            ->post(route('clients.assets.store', $this->client), ['source' => 'upload', 'file' => $svg])
            ->assertSessionHasErrors('file');

        $this->assertSame(0, $this->client->assets()->count());
    }

    public function test_a_file_whose_content_does_not_match_its_extension_is_refused(): void
    {
        Storage::fake('local');
        $html = $this->realFile('holiday.jpg', '<!DOCTYPE html><html><body><script>steal()</script></body></html>');

        $this->actingAs($this->owner)
            ->post(route('clients.assets.store', $this->client), ['source' => 'upload', 'file' => $html])
            ->assertSessionHasErrors('file');

        $this->assertSame(0, $this->client->assets()->count());
    }

    public function test_a_real_image_still_uploads(): void
    {
        Storage::fake('local');

        $this->actingAs($this->owner)
            ->post(route('clients.assets.store', $this->client), ['source' => 'upload', 'file' => UploadedFile::fake()->image('cover.png', 40, 40)])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, $this->client->assets()->count());
    }

    public function test_a_file_the_virus_scanner_flags_is_refused(): void
    {
        Storage::fake('local');
        $this->app->instance(VirusScanner::class, new class implements VirusScanner
        {
            public function scan(string $path): ?string
            {
                return 'Eicar-Test-Signature FOUND';
            }
        });

        $this->actingAs($this->owner)
            ->post(route('clients.assets.store', $this->client), ['source' => 'upload', 'file' => UploadedFile::fake()->image('cover.png', 40, 40)])
            ->assertSessionHasErrors('file');

        $this->assertSame(0, $this->client->assets()->count());
    }

    public function test_clamav_fails_closed_when_the_daemon_is_unreachable(): void
    {
        config(['security.uploads.clamav' => ['host' => '127.0.0.1', 'port' => 1, 'timeout' => 1, 'fail_closed' => true]]);
        $path = tempnam(sys_get_temp_dir(), 'scan');
        file_put_contents((string) $path, 'hello');

        $this->assertSame('scanner unavailable', (new ClamAvVirusScanner)->scan((string) $path));

        config(['security.uploads.clamav.fail_closed' => false]);
        $this->assertNull((new ClamAvVirusScanner)->scan((string) $path));

        @unlink((string) $path);
    }

    public function test_sniffed_types_must_fit_the_extension(): void
    {
        $this->assertTrue(SafeUpload::typeFits('jpg', 'image/jpeg'));
        $this->assertTrue(SafeUpload::typeFits('mov', 'video/quicktime'));
        $this->assertTrue(SafeUpload::typeFits('docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'));
        $this->assertFalse(SafeUpload::typeFits('jpg', 'image/png'));
        $this->assertFalse(SafeUpload::typeFits('png', 'image/svg+xml'));
        $this->assertFalse(SafeUpload::typeFits('pdf', 'text/html'));
        $this->assertFalse(SafeUpload::typeFits('mp4', 'application/x-dosexec'));
        $this->assertFalse(SafeUpload::typeFits('svg', 'image/svg+xml'));
    }

    private function assertSecurityHeaders(TestResponse $response, string $label): void
    {
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'), $label);
        $this->assertSame('SAMEORIGIN', $response->headers->get('X-Frame-Options'), $label);
        $this->assertSame('strict-origin-when-cross-origin', $response->headers->get('Referrer-Policy'), $label);
        $this->assertStringContainsString('camera=()', (string) $response->headers->get('Permissions-Policy'), $label);
        $this->assertStringContainsString("default-src 'self'", (string) $response->headers->get('Content-Security-Policy'), $label);
        $this->assertNotNull($response->headers->get('X-Request-Id'), $label);
    }

    /**
     * An upload backed by a real temp file, so the type is sniffed from its content (Laravel's
     * fake files report the type their name suggests).
     */
    private function realFile(string $name, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'upl');
        file_put_contents((string) $path, $content);

        return new UploadedFile((string) $path, $name, null, null, true);
    }
}
