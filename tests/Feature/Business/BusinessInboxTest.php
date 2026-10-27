<?php

namespace Tests\Feature\Business;

use App\Contracts\CommentSource;
use App\Contracts\ReplyAssistant;
use App\Enums\MessageDirection;
use App\Enums\Role;
use App\Models\AiUsageLog;
use App\Models\Client;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Inbox\Concerns\BuildsInboxFixtures;
use Tests\Feature\Inbox\Concerns\FakeCommentSource;
use Tests\Feature\Inbox\Concerns\FakeReplyAssistant;
use Tests\TestCase;

/**
 * Step CG-1.1 — the social inbox switched on for the business/creator shell. The inbox is a shared
 * surface (one `/inbox` route, like the calendar): a business workspace reaches it, every query is
 * org-scoped to its single brand, assignment UI hides for a solo seat, and the same human-sends
 * guarantee holds. Cross-org conversations stay invisible.
 */
class BusinessInboxTest extends TestCase
{
    use BuildsInboxFixtures, RefreshDatabase;

    /**
     * @return array{0: Organization, 1: User, 2: Client}
     */
    private function business(array $orgAttributes = []): array
    {
        $organization = Organization::factory()->business()->create($orgAttributes);
        $brand = Client::factory()->for($organization, 'organization')->primary()->create();
        $owner = User::factory()->for($organization, 'organization')->role(Role::Owner)->create();

        return [$organization, $owner, $brand];
    }

    public function test_a_business_owner_sees_the_inbox_scoped_to_their_brand(): void
    {
        [$org, $owner, $brand] = $this->business();
        $this->conversation($org, $brand, ['is_reply_needed' => true]);

        $this->actingAs($owner)
            ->get(route('inbox.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('inbox/index')
                // Org-scoped, so a one-brand workspace only ever sees its own conversations.
                ->has('conversations.data', 1)
                // A solo creator never assigns a comment to anyone.
                ->where('showAssignment', false));
    }

    public function test_assignment_appears_once_the_workspace_has_a_second_seat(): void
    {
        [$org, $owner] = $this->business();
        User::factory()->for($org, 'organization')->role(Role::Strategist)->create();

        $this->actingAs($owner)
            ->get(route('inbox.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('showAssignment', true));
    }

    public function test_a_business_owner_drafts_and_sends_a_reply_on_their_brand(): void
    {
        config(['ai.enabled' => true]);

        [$org, $owner, $brand] = $this->business();
        $org->forceFill(['ai_enabled' => true])->save();
        $conversation = $this->conversation($org, $brand, ['is_reply_needed' => true]);

        $assistant = new FakeReplyAssistant('Thanks so much — yes we do!');
        $this->app->instance(ReplyAssistant::class, $assistant);
        $source = new FakeCommentSource;
        $this->app->instance(CommentSource::class, $source);

        // Draft returns text and never reaches the vendor reply path.
        $this->actingAs($owner)
            ->postJson(route('inbox.conversations.draft', $conversation), ['instruction' => 'be warm'])
            ->assertOk()
            ->assertJson(['text' => 'Thanks so much — yes we do!']);

        $this->assertSame([], $source->replyCalls);
        $this->assertSame(1, AiUsageLog::query()->where('method', 'inbox_reply')->count());

        // A human send writes one outbound message and calls the vendor once.
        $this->actingAs($owner)
            ->postJson(route('inbox.conversations.reply', $conversation), ['body' => 'Yes, we do!'])
            ->assertOk();

        $this->assertCount(1, $source->replyCalls);
        $this->assertCount(1, $conversation->messages()->where('direction', MessageDirection::Outbound)->get());
        $conversation->refresh();
        $this->assertFalse($conversation->is_reply_needed);
        $this->assertNotNull($conversation->first_response_at);
    }

    public function test_a_conversation_from_another_org_is_refused(): void
    {
        [, $owner] = $this->business();

        // A different (agency) org's conversation reaches the policy and is refused (tenant isolation).
        $otherOrg = $this->inboxOrg();
        $foreign = $this->conversation($otherOrg);

        $this->actingAs($owner)
            ->getJson(route('inbox.conversations.show', $foreign))
            ->assertForbidden();
    }

    public function test_a_business_viewer_cannot_reply(): void
    {
        [$org, , $brand] = $this->business();
        $viewer = User::factory()->for($org, 'organization')->role(Role::Viewer)->create();
        $conversation = $this->conversation($org, $brand, ['is_reply_needed' => true]);

        $this->app->instance(CommentSource::class, $source = new FakeCommentSource);

        $this->actingAs($viewer)
            ->postJson(route('inbox.conversations.reply', $conversation), ['body' => 'Hi'])
            ->assertForbidden();

        $this->assertSame([], $source->replyCalls);
    }
}
