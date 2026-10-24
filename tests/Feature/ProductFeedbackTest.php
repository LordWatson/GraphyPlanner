<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Client;
use App\Models\Organization;
use App\Models\ProductFeedback;
use App\Models\User;
use App\Notifications\ProductFeedbackReceived;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Step M-8.2 — the staff "Send feedback" panel: stored, scoped to the sender's org, and the super
 * admin is notified. Client reviewers and super admins never reach it.
 */
class ProductFeedbackTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();
        $this->staff = User::factory()->for($this->org, 'organization')->role(Role::Strategist)->create();
    }

    public function test_a_staff_member_can_send_feedback_scoped_to_their_org(): void
    {
        Notification::fake();
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($this->staff)
            ->from(route('dashboard'))
            ->post(route('feedback.store'), [
                'message' => 'The approval queue is a huge help.',
                'page' => '/clients/5/posts',
            ])
            ->assertRedirect(route('dashboard'));

        $feedback = ProductFeedback::withoutOrgScope()->first();
        $this->assertNotNull($feedback);
        $this->assertSame($this->org->id, $feedback->org_id);
        $this->assertSame($this->staff->id, $feedback->user_id);
        $this->assertSame('/clients/5/posts', $feedback->page);
        $this->assertSame('The approval queue is a huge help.', $feedback->message);

        Notification::assertSentTo($superAdmin, ProductFeedbackReceived::class);
    }

    public function test_the_stored_org_is_the_senders_org_not_a_spoofed_one(): void
    {
        $otherOrg = Organization::factory()->create();

        $this->actingAs($this->staff)
            ->post(route('feedback.store'), [
                'message' => 'Trying to attach this to someone else.',
                'org_id' => $otherOrg->id,
                'page' => '/dashboard',
            ])
            ->assertRedirect();

        $feedback = ProductFeedback::withoutOrgScope()->firstOrFail();
        $this->assertSame($this->org->id, $feedback->org_id);
    }

    public function test_feedback_is_isolated_to_the_organization_in_queries(): void
    {
        $orgB = Organization::factory()->create();
        $staffB = User::factory()->for($orgB, 'organization')->role(Role::Owner)->create();

        ProductFeedback::factory()->create(['org_id' => $this->org->id, 'user_id' => $this->staff->id]);
        ProductFeedback::factory()->create(['org_id' => $orgB->id, 'user_id' => $staffB->id]);

        // Org A's member only sees org A's feedback (the OrganizationScope safety net).
        $this->actingAs($this->staff);
        $this->assertSame(1, ProductFeedback::query()->count());
        $this->assertSame($this->org->id, ProductFeedback::query()->firstOrFail()->org_id);

        // A super admin (org-less) sees every org's feedback.
        $this->actingAs(User::factory()->superAdmin()->create());
        $this->assertSame(2, ProductFeedback::query()->count());
    }

    public function test_an_empty_message_is_rejected(): void
    {
        $this->actingAs($this->staff)
            ->post(route('feedback.store'), ['message' => ''])
            ->assertSessionHasErrors('message');

        $this->assertSame(0, ProductFeedback::withoutOrgScope()->count());
    }

    public function test_a_client_reviewer_cannot_send_feedback(): void
    {
        $client = Client::factory()->for($this->org, 'organization')->create();
        $reviewer = User::factory()->for($this->org, 'organization')
            ->role(Role::ClientReviewer)
            ->create(['client_id' => $client->id]);

        // `portal.redirect` sends a reviewer out of the internal route; nothing is stored.
        $this->actingAs($reviewer)
            ->post(route('feedback.store'), ['message' => 'From a client reviewer.'])
            ->assertRedirect();

        $this->assertSame(0, ProductFeedback::withoutOrgScope()->count());
    }

    public function test_a_super_admin_cannot_send_feedback(): void
    {
        // `org.member` sends the org-less super admin to the console; nothing is stored.
        $this->actingAs(User::factory()->superAdmin()->create())
            ->post(route('feedback.store'), ['message' => 'From a super admin.'])
            ->assertRedirect();

        $this->assertSame(0, ProductFeedback::withoutOrgScope()->count());
    }

    public function test_the_feedback_inbox_lists_notes_and_filters_by_org(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $orgB = Organization::factory()->create(['name' => 'Second Org']);
        $staffB = User::factory()->for($orgB, 'organization')->role(Role::Owner)->create();

        ProductFeedback::factory()->create(['org_id' => $this->org->id, 'user_id' => $this->staff->id, 'message' => 'From org A.']);
        ProductFeedback::factory()->create(['org_id' => $orgB->id, 'user_id' => $staffB->id, 'message' => 'From org B.']);

        $this->actingAs($superAdmin)
            ->get(route('super-admin.feedback.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('super-admin/feedback/index')
                ->where('feedback.total', 2)
                ->has('organizations', 2));

        $this->actingAs($superAdmin)
            ->get(route('super-admin.feedback.index', ['org' => $orgB->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('feedback.total', 1)
                ->where('feedback.data.0.message', 'From org B.'));
    }
}
