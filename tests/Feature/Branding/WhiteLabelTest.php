<?php

namespace Tests\Feature\Branding;

use App\Enums\ClientReportStatus;
use App\Enums\OrganizationType;
use App\Enums\Role;
use App\Mail\ClientInvitationMail;
use App\Models\Client;
use App\Models\ClientInvitation;
use App\Models\ClientReport;
use App\Models\Organization;
use App\Models\User;
use App\Services\Branding\BrandingResolver;
use App\Support\Branding\Branding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Step GTM-3.1 — an agency's own branding on the three surfaces its client sees: the portal shell,
 * the monthly report and outbound mail. Every test runs the same surface twice — on a plan that
 * includes white labelling and on one that doesn't — because the thing being tested is that the
 * **plan** decides, not what happens to be stored.
 */
class WhiteLabelTest extends TestCase
{
    use RefreshDatabase;

    private const ACCENT = '#7A1FA2';

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function agency(string $plan = 'agency_standard', array $overrides = []): Organization
    {
        return Organization::factory()->create([
            'type' => OrganizationType::Agency,
            'plan' => $plan,
            'name' => 'Northside Studio',
            'brand_accent' => self::ACCENT,
            'brand_sender_name' => 'Northside',
            'brand_reply_to' => 'hello@northside.test',
            'brand_footer' => 'Northside Studio Ltd',
            ...$overrides,
        ]);
    }

    public function test_the_resolver_hands_back_alima_without_the_plan_gate(): void
    {
        $withGate = app(BrandingResolver::class)->for($this->agency());
        $this->assertTrue($withGate->isWhiteLabel);
        $this->assertSame('Northside Studio', $withGate->name);
        $this->assertSame(self::ACCENT, $withGate->accent);
        $this->assertSame('Northside', $withGate->senderName);

        // A business plan has no white label, whatever is stored on the row.
        $withoutGate = app(BrandingResolver::class)->for(
            $this->agency('business_pro', ['type' => OrganizationType::Business]),
        );
        $this->assertFalse($withoutGate->isWhiteLabel);
        $this->assertSame(config('app.name'), $withoutGate->name);
        $this->assertSame(Branding::DEFAULT_ACCENT, $withoutGate->accent);
        $this->assertNull($withoutGate->logoUrl);

        // And no organization at all is Alima too — nothing here can fail open.
        $this->assertFalse(app(BrandingResolver::class)->for(null)->isWhiteLabel);
    }

    public function test_the_client_portal_shell_carries_the_agency_branding_only_on_a_plan_that_has_it(): void
    {
        foreach ([['agency_standard', true], ['agency_legacy', true], ['business_pro', false]] as [$plan, $expected]) {
            $org = $this->agency($plan);
            $client = Client::factory()->for($org, 'organization')->create();
            $reviewer = User::factory()->for($org, 'organization')->role(Role::ClientReviewer)
                ->create(['client_id' => $client->id]);

            $this->actingAs($reviewer)
                ->get(route('portal.dashboard'))
                ->assertOk()
                ->assertInertia(fn ($page) => $page
                    ->where('portal.branding.is_white_label', $expected)
                    ->where('portal.branding.name', $expected ? 'Northside Studio' : config('app.name'))
                    ->where('portal.branding.accent', $expected ? self::ACCENT : Branding::DEFAULT_ACCENT)
                    ->where('portal.branding.footer', $expected ? 'Northside Studio Ltd' : null));
        }
    }

    public function test_the_monthly_report_drops_the_alima_credit_only_when_white_labelled(): void
    {
        $org = $this->agency();
        $client = Client::factory()->for($org, 'organization')->create();
        $report = ClientReport::query()->create([
            'org_id' => $org->id,
            'client_id' => $client->id,
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'status' => ClientReportStatus::Published,
            'published_at' => now(),
            'snapshot' => [],
        ]);

        $branded = view('pdf.client-report', [
            'report' => $report,
            'snapshot' => $report->snapshot ?? [],
            'branding' => app(BrandingResolver::class)->for($org),
            'brandLogo' => null,
        ])->render();

        $this->assertStringContainsString(self::ACCENT, $branded);
        $this->assertStringContainsString('Northside Studio Ltd', $branded);
        $this->assertStringNotContainsString('Prepared with', $branded);

        $plain = view('pdf.client-report', [
            'report' => $report,
            'snapshot' => $report->snapshot ?? [],
            'branding' => Branding::alima(),
            'brandLogo' => null,
        ])->render();

        $this->assertStringContainsString('Prepared with', $plain);
        $this->assertStringContainsString('Marketing brighter together.', $plain);
    }

    public function test_a_client_email_carries_the_agency_name_sender_and_reply_to(): void
    {
        $org = $this->agency();
        $client = Client::factory()->for($org, 'organization')->create(['name' => 'Acme Bakery']);
        $invitation = ClientInvitation::factory()->for($client)->create();

        $mailable = new ClientInvitationMail($invitation, 'https://example.test/client-invite/token');
        $rendered = $mailable->render();

        $this->assertStringContainsString(self::ACCENT, $rendered);
        $this->assertStringContainsString('Northside Studio Ltd', $rendered);
        $this->assertStringNotContainsString('alima_logo_cream', $rendered);
        $this->assertStringNotContainsString('Marketing brighter together', $rendered);

        // The display name and reply-to are the agency's; the envelope from-address stays ours,
        // because SPF and DKIM are signed for it.
        $this->assertTrue($mailable->hasReplyTo('hello@northside.test'));
        $this->assertTrue($mailable->hasFrom(config('mail.from.address')));
    }

    public function test_the_same_email_keeps_alima_chrome_without_the_plan_gate(): void
    {
        $org = $this->agency('business_pro', ['type' => OrganizationType::Business]);
        $client = Client::factory()->for($org, 'organization')->create();
        $invitation = ClientInvitation::factory()->for($client)->create();

        $rendered = (new ClientInvitationMail($invitation, 'https://example.test/client-invite/token'))->render();

        $this->assertStringContainsString('alima_logo_cream', $rendered);
        $this->assertStringContainsString('Marketing brighter together', $rendered);
        $this->assertStringNotContainsString(self::ACCENT, $rendered);
    }

    public function test_the_owner_uploads_and_removes_a_logo_and_it_reaches_the_report_as_bytes(): void
    {
        Storage::fake(config('assets.disk'));

        $org = $this->agency();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();

        $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('organization.logo.store'), [
                'brand_logo' => UploadedFile::fake()->image('logo.png', 400, 120),
            ])
            ->assertRedirect();

        $org->refresh();
        $this->assertNotNull($org->brand_logo_path);
        Storage::disk(config('assets.disk'))->assertExists($org->brand_logo_path);

        // dompdf can't fetch a URL, so the report needs the bytes inline.
        $dataUri = app(BrandingResolver::class)->logoDataUri($org);
        $this->assertNotNull($dataUri);
        $this->assertStringStartsWith('data:image/png;base64,', $dataUri);

        $path = $org->brand_logo_path;

        $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => time()])
            ->delete(route('organization.logo.destroy'))
            ->assertRedirect();

        $this->assertNull($org->refresh()->brand_logo_path);
        Storage::disk(config('assets.disk'))->assertMissing($path);
    }

    public function test_branding_is_stored_without_the_plan_but_never_resolved_with_it(): void
    {
        $org = Organization::factory()->create([
            'type' => OrganizationType::Business,
            'plan' => 'business_pro',
            'name' => 'Solo Studio',
        ]);
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();

        $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => time()])
            ->put(route('organization.update'), [
                'default_timezone' => 'Europe/London',
                'default_currency' => 'GBP',
                'brand_accent' => self::ACCENT,
                'brand_footer' => 'Solo Studio Ltd',
            ])
            ->assertRedirect();

        // Kept, so a later upgrade doesn't ask them to type it again...
        $this->assertSame(self::ACCENT, $org->refresh()->brand_accent);
        // ...and ignored until then.
        $this->assertFalse(app(BrandingResolver::class)->for($org)->isWhiteLabel);
    }

    public function test_an_accent_that_is_not_a_hex_colour_is_refused(): void
    {
        $org = $this->agency();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();

        $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => time()])
            ->put(route('organization.update'), [
                'default_timezone' => 'Europe/London',
                'default_currency' => 'GBP',
                'brand_accent' => 'javascript:alert(1)',
            ])
            ->assertSessionHasErrors('brand_accent');

        $this->assertSame(self::ACCENT, $org->refresh()->brand_accent);
    }
}
