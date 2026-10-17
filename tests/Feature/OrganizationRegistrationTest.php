<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Mail\OrganizationWelcomeMail;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Step 15.2 — the public organization signup.
 */
class OrganizationRegistrationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, string>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'organization_name' => 'Northlight Studio',
            'default_timezone' => 'Europe/London',
            'default_currency' => 'GBP',
            'name' => 'Rae Morgan',
            'email' => 'rae@northlight.test',
            'password' => 'password',
            'password_confirmation' => 'password',
            ...$overrides,
        ];
    }

    public function test_the_signup_page_renders_with_its_options(): void
    {
        $this->get(route('organizations.register'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('organizations/register')
                ->has('timezones')
                ->has('currencies')
                ->has('passwordRules'));
    }

    public function test_the_old_register_url_redirects_to_the_organization_signup(): void
    {
        $this->get('/register')->assertRedirect('/register-organization');
    }

    public function test_signing_up_creates_the_organization_and_its_owner_together_and_signs_them_in(): void
    {
        Mail::fake();
        Notification::fake();

        $response = $this->post(route('organizations.register.store'), $this->payload());

        $organization = Organization::query()->sole();
        $owner = User::query()->sole();

        $this->assertSame('Northlight Studio', $organization->name);
        $this->assertSame('northlight-studio', $organization->slug);
        $this->assertSame('Europe/London', $organization->default_timezone);
        $this->assertSame('GBP', $organization->default_currency);

        $this->assertSame($organization->id, $owner->org_id);
        $this->assertSame(Role::Owner, $owner->role);
        $this->assertSame('rae@northlight.test', $owner->email);
        $this->assertNull($owner->email_verified_at);

        $this->assertAuthenticatedAs($owner);
        $response->assertRedirect(route('dashboard'));

        // Fortify's email verification runs for the new owner, and the welcome email is queued.
        Notification::assertSentTo($owner, VerifyEmail::class);
        Mail::assertQueued(OrganizationWelcomeMail::class, fn (OrganizationWelcomeMail $mail) => $mail->hasTo('rae@northlight.test')
            && $mail->owner->is($owner)
            && $mail->setPasswordUrl === null);
    }

    public function test_an_unverified_new_owner_is_asked_to_verify_before_using_the_studio(): void
    {
        Mail::fake();
        Notification::fake();

        $this->post(route('organizations.register.store'), $this->payload());

        $this->get(route('dashboard'))->assertRedirect(route('verification.notice'));
    }

    public function test_a_duplicate_email_is_rejected_and_no_partial_organization_is_created(): void
    {
        Mail::fake();
        User::factory()->create(['email' => 'rae@northlight.test']);
        $organizationsBefore = Organization::query()->count();

        $response = $this->from(route('organizations.register'))
            ->post(route('organizations.register.store'), $this->payload(['email' => 'RAE@Northlight.test ']));

        $response->assertSessionHasErrors('email');
        $this->assertSame($organizationsBefore, Organization::query()->count());
        $this->assertGuest();
        Mail::assertNothingQueued();
    }

    public function test_invalid_timezone_currency_and_short_name_are_rejected(): void
    {
        $this->post(route('organizations.register.store'), $this->payload([
            'organization_name' => 'A',
            'default_timezone' => 'Mars/Olympus',
            'default_currency' => 'ZZZ',
        ]))->assertSessionHasErrors(['organization_name', 'default_timezone', 'default_currency']);

        $this->assertSame(0, Organization::query()->count());
    }

    public function test_slugs_stay_unique_and_never_take_the_preview_fixtures(): void
    {
        Mail::fake();
        Notification::fake();
        Organization::factory()->create(['slug' => 'northlight-studio']);

        $this->post(route('organizations.register.store'), $this->payload());
        $this->assertSame('northlight-studio-2', User::query()->where('email', 'rae@northlight.test')->sole()->organization->slug);

        auth()->logout();

        $this->post(route('organizations.register.store'), $this->payload([
            'organization_name' => 'Preview Studio',
            'email' => 'someone@else.test',
        ]));
        $this->assertSame('preview-studio-2', User::query()->where('email', 'someone@else.test')->sole()->organization->slug);
    }

    public function test_signed_in_users_cannot_open_the_signup(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($owner)->get(route('organizations.register'))->assertRedirect();
    }
}
