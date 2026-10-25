<?php

namespace Tests\Feature;

use App\Enums\BrandKind;
use App\Enums\OrganizationType;
use App\Enums\Role;
use App\Mail\OrganizationWelcomeMail;
use App\Models\Client;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Step B-2.1 — the business/creator signup at `/start`.
 */
class BusinessRegistrationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'organization_name' => 'Sourdough & Co.',
            'brand_kind' => BrandKind::Business->value,
            'default_timezone' => 'Europe/London',
            'default_currency' => 'GBP',
            'name' => 'Jess Baker',
            'email' => 'jess@sourdough.test',
            'password' => 'password',
            'password_confirmation' => 'password',
            ...$overrides,
        ];
    }

    public function test_the_start_page_renders_with_its_options(): void
    {
        $this->get(route('organizations.start'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('organizations/start')
                ->has('timezones')
                ->has('currencies')
                ->has('passwordRules')
                ->has('brandKinds', 2));
    }

    public function test_signing_up_creates_a_business_org_with_its_primary_brand_and_brand_brain(): void
    {
        Mail::fake();
        Notification::fake();

        $response = $this->post(route('organizations.start.store'), $this->payload());

        $organization = Organization::query()->sole();
        $owner = User::query()->sole();
        $brand = Client::query()->sole();

        $this->assertTrue($organization->isBusiness());
        $this->assertSame(OrganizationType::Business, $organization->type);

        $this->assertSame($organization->id, $owner->org_id);
        $this->assertSame(Role::Owner, $owner->role);
        $this->assertNull($owner->email_verified_at);

        // One primary brand, flagged, with the chosen kind and an empty Brand Brain to fill.
        $this->assertTrue($brand->is_primary);
        $this->assertSame($organization->id, $brand->org_id);
        $this->assertSame(BrandKind::Business, $brand->brand_kind);
        $this->assertSame('Sourdough & Co.', $brand->name);
        $this->assertDatabaseHas('brand_brains', ['client_id' => $brand->id]);

        $this->assertAuthenticatedAs($owner);
        $response->assertRedirect(route('business.onboarding'));

        // One welcome email, one link: the signed verification URL. No separate verification email.
        Notification::assertNothingSent();
        Mail::assertQueued(OrganizationWelcomeMail::class, fn (OrganizationWelcomeMail $mail) => $mail->hasTo('jess@sourdough.test')
            && $mail->owner->organization->isBusiness()
            && $mail->setPasswordUrl === null
            && $mail->verifyUrl !== null
            && str_contains((string) $mail->verifyUrl, 'signature='));
    }

    public function test_a_creator_signup_stores_the_creator_brand_kind(): void
    {
        Mail::fake();
        Notification::fake();

        $this->post(route('organizations.start.store'), $this->payload([
            'brand_kind' => BrandKind::Creator->value,
            'email' => 'jack@creator.test',
        ]));

        $this->assertSame(BrandKind::Creator, Client::query()->sole()->brand_kind);
    }

    public function test_the_brand_kind_is_required_and_validated(): void
    {
        $this->from(route('organizations.start'))
            ->post(route('organizations.start.store'), $this->payload(['brand_kind' => 'banana']))
            ->assertSessionHasErrors('brand_kind');

        $this->assertSame(0, Organization::query()->count());
    }

    public function test_invalid_timezone_currency_and_short_name_are_rejected(): void
    {
        $this->post(route('organizations.start.store'), $this->payload([
            'organization_name' => 'A',
            'default_timezone' => 'Mars/Olympus',
            'default_currency' => 'ZZZ',
        ]))->assertSessionHasErrors(['organization_name', 'default_timezone', 'default_currency']);

        $this->assertSame(0, Organization::query()->count());
    }

    public function test_a_duplicate_email_is_rejected_and_no_partial_workspace_is_created(): void
    {
        Mail::fake();
        User::factory()->create(['email' => 'jess@sourdough.test']);

        $this->from(route('organizations.start'))
            ->post(route('organizations.start.store'), $this->payload())
            ->assertSessionHasErrors('email');

        $this->assertSame(0, Client::query()->count());
        $this->assertGuest();
        Mail::assertNothingQueued();
    }

    public function test_the_signup_is_throttled_per_ip(): void
    {
        // Invalid payloads keep the visitor a guest (no login), so every request reaches the
        // throttle rather than being bounced by the `guest` middleware after the first success.
        for ($attempt = 0; $attempt < 6; $attempt++) {
            $this->post(route('organizations.start.store'), [])->assertStatus(302);
        }

        $this->post(route('organizations.start.store'), [])->assertStatus(429);
    }

    public function test_signed_in_users_cannot_open_the_business_signup(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($owner)->get(route('organizations.start'))->assertRedirect();
    }
}
