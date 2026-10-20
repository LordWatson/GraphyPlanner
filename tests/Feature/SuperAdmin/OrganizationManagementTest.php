<?php

namespace Tests\Feature\SuperAdmin;

use App\Enums\Role;
use App\Mail\OrganizationWelcomeMail;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Step 15.3 — the super admin's organization CRUD, suspension and onboarding.
 */
class OrganizationManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = User::factory()->superAdmin()->create();
    }

    public function test_the_directory_lists_every_organization_with_owner_and_counts(): void
    {
        $organization = Organization::factory()->create(['name' => 'Northlight Studio']);
        $owner = User::factory()->for($organization, 'organization')->role(Role::Owner)->create(['name' => 'Rae Morgan']);
        User::factory()->for($organization, 'organization')->role(Role::Strategist)->create();
        Client::factory()->count(2)->for($organization, 'organization')->create();
        Organization::factory()->create();

        $this->actingAs($this->superAdmin)
            ->get(route('super-admin.organizations.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('super-admin/organizations/index')
                ->has('organizations.data', 2)
                ->where('counts.all', 2)
                ->where('organizations.data', fn ($rows) => collect($rows)->contains(fn ($row) => $row['name'] === 'Northlight Studio'
                    && $row['owner']['email'] === $owner->email
                    && $row['users_count'] === 2
                    && $row['clients_count'] === 2)));
    }

    public function test_the_directory_searches_by_organization_name_and_by_member_email(): void
    {
        $northlight = Organization::factory()->create(['name' => 'Northlight Studio']);
        $harbour = Organization::factory()->create(['name' => 'Harbour & Co']);
        User::factory()->for($harbour, 'organization')->create(['email' => 'sam@harbour.test']);

        $this->actingAs($this->superAdmin)
            ->get(route('super-admin.organizations.index', ['search' => 'northl']))
            ->assertInertia(fn ($page) => $page
                ->has('organizations.data', 1)
                ->where('organizations.data.0.id', $northlight->id));

        $this->actingAs($this->superAdmin)
            ->get(route('super-admin.organizations.index', ['search' => 'SAM@harbour']))
            ->assertInertia(fn ($page) => $page
                ->has('organizations.data', 1)
                ->where('organizations.data.0.id', $harbour->id));
    }

    public function test_the_directory_filters_by_status(): void
    {
        Organization::factory()->create();
        $suspended = Organization::factory()->suspended()->create();

        $this->actingAs($this->superAdmin)
            ->get(route('super-admin.organizations.index', ['status' => 'suspended']))
            ->assertInertia(fn ($page) => $page
                ->has('organizations.data', 1)
                ->where('organizations.data.0.id', $suspended->id)
                ->where('organizations.data.0.is_suspended', true));

        $this->actingAs($this->superAdmin)
            ->get(route('super-admin.organizations.index', ['status' => 'bogus']))
            ->assertSessionHasErrors('status');
    }

    public function test_the_detail_page_shows_details_members_and_counts(): void
    {
        $organization = Organization::factory()->create();
        User::factory()->for($organization, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($organization, 'organization')->create();
        Post::factory()->for($client)->create();

        $this->actingAs($this->superAdmin)
            ->get(route('super-admin.organizations.show', $organization))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('super-admin/organizations/show')
                ->where('organization.id', $organization->id)
                ->has('members', 1)
                ->has('clients', 1)
                ->where('counts.posts', 1)
                ->missing('organization.upload_post_key'));
    }

    public function test_a_super_admin_can_update_an_organizations_details(): void
    {
        $organization = Organization::factory()->create();

        $this->actingAs($this->superAdmin)
            ->put(route('super-admin.organizations.update', $organization), [
                'name' => 'Renamed Studio',
                'slug' => 'Renamed Studio',
                'default_timezone' => 'America/New_York',
                'default_currency' => 'EUR',
                'ai_monthly_image_quota' => 250,
            ])
            ->assertRedirect(route('super-admin.organizations.show', $organization));

        $organization->refresh();
        $this->assertSame('Renamed Studio', $organization->name);
        $this->assertSame('renamed-studio', $organization->slug);
        $this->assertSame('America/New_York', $organization->default_timezone);
        $this->assertSame('EUR', $organization->default_currency);
        $this->assertSame(250, $organization->ai_monthly_image_quota);
    }

    public function test_updating_rejects_a_taken_slug_and_the_preview_slug(): void
    {
        Organization::factory()->create(['slug' => 'taken']);
        $organization = Organization::factory()->create();
        $payload = ['name' => 'Studio', 'default_timezone' => 'UTC', 'default_currency' => 'USD'];

        $this->actingAs($this->superAdmin)
            ->put(route('super-admin.organizations.update', $organization), [...$payload, 'slug' => 'taken'])
            ->assertSessionHasErrors('slug');

        $this->actingAs($this->superAdmin)
            ->put(route('super-admin.organizations.update', $organization), [...$payload, 'slug' => Organization::PREVIEW_SLUG])
            ->assertSessionHasErrors('slug');
    }

    public function test_suspending_locks_members_out_with_the_support_page_but_not_the_super_admin(): void
    {
        $organization = Organization::factory()->create(['name' => 'Northlight Studio']);
        $owner = User::factory()->for($organization, 'organization')->role(Role::Owner)->create();

        $this->actingAs($this->superAdmin)
            ->post(route('super-admin.organizations.suspend', $organization), ['reason' => 'Invoice overdue — please get in touch.'])
            ->assertRedirect(route('super-admin.organizations.show', $organization));

        $organization->refresh();
        $this->assertTrue($organization->isSuspended());
        $this->assertSame('Invoice overdue — please get in touch.', $organization->suspension_reason);
        $this->assertSame($this->superAdmin->id, $organization->suspended_by);

        foreach ([route('dashboard'), route('clients.index'), route('profile.edit')] as $url) {
            $this->actingAs($owner)->get($url)
                ->assertForbidden()
                ->assertInertia(fn ($page) => $page
                    ->component('organizations/suspended')
                    ->where('organization.name', 'Northlight Studio')
                    ->where('reason', 'Invoice overdue — please get in touch.'));
        }

        // Signing out still works for a locked-out member.
        $this->actingAs($owner)->post(route('logout'))->assertRedirect();
        $this->assertGuest();

        // The super admin is unaffected and can still manage the organization.
        $this->actingAs($this->superAdmin)->get(route('super-admin.organizations.show', $organization))->assertOk();
    }

    public function test_reinstating_restores_member_access(): void
    {
        $organization = Organization::factory()->suspended()->create();
        $owner = User::factory()->for($organization, 'organization')->role(Role::Owner)->create();

        $this->actingAs($owner)->get(route('dashboard'))->assertForbidden();

        $this->actingAs($this->superAdmin)
            ->post(route('super-admin.organizations.reinstate', $organization))
            ->assertRedirect(route('super-admin.organizations.show', $organization));

        $this->assertFalse($organization->fresh()->isSuspended());
        $this->actingAs($owner->fresh())->get(route('dashboard'))->assertOk();
    }

    public function test_deleting_requires_typing_the_name(): void
    {
        $organization = Organization::factory()->create(['name' => 'Northlight Studio']);

        $this->actingAs($this->superAdmin)
            ->delete(route('super-admin.organizations.destroy', $organization), ['confirm_name' => 'Wrong name', 'password' => 'password'])
            ->assertSessionHasErrors('confirm_name');

        $this->assertModelExists($organization);
    }

    public function test_deleting_cascades_org_data_and_removes_its_members(): void
    {
        $organization = Organization::factory()->create(['name' => 'Northlight Studio']);
        $owner = User::factory()->for($organization, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($organization, 'organization')->create();
        $post = Post::factory()->for($client)->create();
        $invoice = Invoice::factory()->for($client)->create();
        $survivor = Organization::factory()->create();
        $survivingMember = User::factory()->for($survivor, 'organization')->create();

        $this->actingAs($this->superAdmin)
            ->delete(route('super-admin.organizations.destroy', $organization), ['confirm_name' => ' northlight studio ', 'password' => 'password'])
            ->assertRedirect(route('super-admin.organizations.index'));

        $this->assertModelMissing($organization);
        $this->assertModelMissing($client);
        $this->assertModelMissing($post);
        $this->assertModelMissing($invoice);
        // `users.org_id` is nullOnDelete — members must be removed explicitly, never orphaned.
        $this->assertModelMissing($owner);

        $this->assertModelExists($survivor);
        $this->assertModelExists($survivingMember);
        $this->assertModelExists($this->superAdmin);
    }

    public function test_the_preview_fixture_cannot_be_suspended_or_deleted(): void
    {
        $preview = Organization::factory()->create(['slug' => Organization::PREVIEW_SLUG, 'name' => 'Preview Studio']);

        $this->actingAs($this->superAdmin)
            ->post(route('super-admin.organizations.suspend', $preview))
            ->assertSessionHasErrors('organization');

        $this->actingAs($this->superAdmin)
            ->delete(route('super-admin.organizations.destroy', $preview), ['confirm_name' => 'Preview Studio', 'password' => 'password'])
            ->assertSessionHasErrors('organization');

        $this->assertNull($preview->fresh()->suspended_at);
    }

    public function test_a_super_admin_can_onboard_an_organization_for_a_customer(): void
    {
        Mail::fake();

        $response = $this->actingAs($this->superAdmin)->post(route('super-admin.organizations.store'), [
            'organization_name' => 'Harbour & Co',
            'default_timezone' => 'Australia/Sydney',
            'default_currency' => 'AUD',
            'name' => 'Sam Lee',
            'email' => 'Sam@Harbour.test',
        ]);

        $owner = User::query()->where('email', 'sam@harbour.test')->sole();
        $organization = $owner->organization;

        $response->assertRedirect(route('super-admin.organizations.show', $organization));
        $this->assertSame('Harbour & Co', $organization->name);
        $this->assertSame(Role::Owner, $owner->role);
        // The set-password link is the only way in, so it counts as email verification.
        $this->assertNotNull($owner->email_verified_at);
        // The super admin stays signed in as themselves.
        $this->assertAuthenticatedAs($this->superAdmin);

        Mail::assertQueued(OrganizationWelcomeMail::class, fn (OrganizationWelcomeMail $mail) => $mail->hasTo('sam@harbour.test')
            && str_contains((string) $mail->setPasswordUrl, '/reset-password/'));
    }

    public function test_onboarding_rejects_an_email_that_already_has_an_account(): void
    {
        Mail::fake();
        User::factory()->create(['email' => 'sam@harbour.test']);

        $this->actingAs($this->superAdmin)->post(route('super-admin.organizations.store'), [
            'organization_name' => 'Harbour & Co',
            'default_timezone' => 'UTC',
            'default_currency' => 'USD',
            'name' => 'Sam Lee',
            'email' => 'sam@harbour.test',
        ])->assertSessionHasErrors('email');

        $this->assertFalse(Organization::query()->where('name', 'Harbour & Co')->exists());
        Mail::assertNothingQueued();
    }
}
