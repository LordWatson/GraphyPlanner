<?php

namespace Tests\Feature\Inbox;

use App\Contracts\CommentSource;
use App\Contracts\ReplyAssistant;
use App\Models\AiUsageLog;
use App\Models\InboxConversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Inbox\Concerns\BuildsInboxFixtures;
use Tests\Feature\Inbox\Concerns\FakeCommentSource;
use Tests\Feature\Inbox\Concerns\FakeReplyAssistant;
use Tests\TestCase;

class AiDraftReplyTest extends TestCase
{
    use BuildsInboxFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ai.enabled' => true]);
    }

    private function enableAi(InboxConversation $conversation): void
    {
        $conversation->organization->forceFill(['ai_enabled' => true])->save();
    }

    public function test_drafting_returns_text_and_never_sends(): void
    {
        $org = $this->inboxOrg();
        $org->forceFill(['ai_enabled' => true])->save();
        $user = $this->staff($org);
        $conversation = $this->conversation($org);

        $assistant = new FakeReplyAssistant('Thanks so much — we ship to Dubai!');
        $this->app->instance(ReplyAssistant::class, $assistant);
        $source = new FakeCommentSource;
        $this->app->instance(CommentSource::class, $source);

        $this->actingAs($user)
            ->postJson(route('inbox.conversations.draft', $conversation), ['instruction' => 'be warm'])
            ->assertOk()
            ->assertJson(['text' => 'Thanks so much — we ship to Dubai!']);

        $this->assertSame(1, $assistant->calls);
        // The critical M-9.3 guarantee: drafting never reaches the vendor reply path.
        $this->assertSame([], $source->replyCalls);
        $this->assertSame(1, AiUsageLog::query()->where('method', 'inbox_reply')->count());
    }

    public function test_a_banned_phrase_is_filtered_out_of_a_draft(): void
    {
        config(['ai.guardrails.prohibited_topics' => ['guaranteed refund']]);

        $org = $this->inboxOrg();
        $org->forceFill(['ai_enabled' => true])->save();
        $user = $this->staff($org);
        $conversation = $this->conversation($org);

        $this->app->instance(ReplyAssistant::class, new FakeReplyAssistant('Sure — a guaranteed refund for everyone!'));

        $this->actingAs($user)
            ->postJson(route('inbox.conversations.draft', $conversation), [])
            ->assertOk()
            ->assertJson(['text' => '']);
    }

    public function test_an_over_budget_org_gets_a_clean_422(): void
    {
        $org = $this->inboxOrg();
        $org->forceFill(['ai_enabled' => true, 'ai_monthly_token_budget' => 100])->save();
        $user = $this->staff($org);
        $conversation = $this->conversation($org);

        AiUsageLog::query()->create([
            'org_id' => $org->id,
            'provider' => 'deepseek',
            'model' => 'deepseek-flash',
            'method' => 'inbox_reply',
            'succeeded' => true,
            'prompt_tokens' => 200,
            'completion_tokens' => 0,
            'cost_estimate' => 0,
        ]);

        $this->app->instance(ReplyAssistant::class, new FakeReplyAssistant);

        $this->actingAs($user)
            ->postJson(route('inbox.conversations.draft', $conversation), [])
            ->assertStatus(422)
            ->assertJson(['budgetExceeded' => true]);
    }

    public function test_ai_disabled_org_is_a_404(): void
    {
        $org = $this->inboxOrg();
        $org->forceFill(['ai_enabled' => false])->save();
        $user = $this->staff($org);
        $conversation = $this->conversation($org);

        $this->actingAs($user)
            ->postJson(route('inbox.conversations.draft', $conversation), [])
            ->assertNotFound();
    }
}
