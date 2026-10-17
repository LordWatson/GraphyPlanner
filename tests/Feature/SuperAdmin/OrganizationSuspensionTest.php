<?php

namespace Tests\Feature\SuperAdmin;

use App\Actions\AI\GenerateDailyBriefsAction;
use App\Actions\Posts\SendUpcomingPostReminderEmailsAction;
use App\Actions\Posts\TransitionPostStatusAction;
use App\Contracts\PublishAdapter;
use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\Role;
use App\Jobs\PublishPostJob;
use App\Models\Client;
use App\Models\DailyBrief;
use App\Models\Organization;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\StaffInvitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Tests\TestCase;

/**
 * Step 15.3 — a suspended organization's scheduled work stays paused, and its portal contacts
 * are locked out too.
 */
class OrganizationSuspensionTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_suspended_organizations_post_is_not_published(): void
    {
        $organization = Organization::factory()->suspended()->create();
        $client = Client::factory()->for($organization, 'organization')->create();
        $post = Post::factory()->for($client)->create(['status' => PostStatus::Scheduled]);
        $account = SocialAccount::factory()->for($client)->create(['org_id' => $organization->id, 'platform' => Platform::LinkedIn]);
        $post->targets()->create(['social_account_id' => $account->id, 'scheduled_at_utc' => now()]);

        $adapter = Mockery::mock(PublishAdapter::class);
        $adapter->shouldNotReceive('publish');

        (new PublishPostJob($post->id))->handle($adapter, app(TransitionPostStatusAction::class));

        // Left `scheduled`, so nothing is lost if the organization is reinstated.
        $this->assertSame(PostStatus::Scheduled, $post->fresh()->status);
    }

    public function test_reminder_emails_skip_suspended_organizations(): void
    {
        Mail::fake();
        $organization = Organization::factory()->suspended()->create();
        User::factory()->for($organization, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($organization, 'organization')->create();
        $post = Post::factory()->for($client)->create(['status' => PostStatus::Scheduled]);
        $account = SocialAccount::factory()->for($client)->create(['org_id' => $organization->id]);
        $post->targets()->create(['social_account_id' => $account->id, 'scheduled_at_utc' => now()->addDay()]);

        $this->assertSame(0, app(SendUpcomingPostReminderEmailsAction::class)());
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
    }

    public function test_daily_briefs_skip_suspended_organizations(): void
    {
        $organization = Organization::factory()->suspended()->create();
        Client::factory()->for($organization, 'organization')->create();

        app(GenerateDailyBriefsAction::class)();

        $this->assertSame(0, DailyBrief::query()->count());
    }

    public function test_a_suspended_organizations_client_portal_contacts_are_locked_out(): void
    {
        $organization = Organization::factory()->suspended()->create();
        $client = Client::factory()->for($organization, 'organization')->create();
        $reviewer = User::factory()->for($organization, 'organization')->role(Role::ClientReviewer)->create(['client_id' => $client->id]);

        $this->actingAs($reviewer)
            ->get(route('portal.dashboard'))
            ->assertForbidden()
            ->assertInertia(fn ($page) => $page->component('organizations/suspended'));
    }

    public function test_accepting_an_invitation_marks_the_email_verified(): void
    {
        // Email verification is enforced (Step 15.2.3); the emailed invite link proves the inbox.
        $organization = Organization::factory()->create();
        $owner = User::factory()->for($organization, 'organization')->role(Role::Owner)->create();
        StaffInvitation::factory()->for($organization, 'organization')->for($owner, 'invitedBy')->create([
            'email' => 'new.hire@studio.test',
            'role' => Role::Designer,
            'token_hash' => hash('sha256', 'plain-token'),
        ]);

        $this->post('/staff-invite/plain-token', [
            'name' => 'New Hire',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertNotNull(User::query()->where('email', 'new.hire@studio.test')->sole()->email_verified_at);
    }
}
