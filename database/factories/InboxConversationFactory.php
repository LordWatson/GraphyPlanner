<?php

namespace Database\Factories;

use App\Enums\CommentSentiment;
use App\Enums\InboxStatus;
use App\Enums\Platform;
use App\Models\Client;
use App\Models\InboxConversation;
use App\Models\SocialAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InboxConversation>
 */
class InboxConversationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $client = Client::factory()->create();
        $account = SocialAccount::factory()->connected()->create([
            'client_id' => $client->id,
            'org_id' => $client->org_id,
            'platform' => Platform::Instagram,
        ]);

        return [
            'org_id' => $client->org_id,
            'client_id' => $client->id,
            'social_account_id' => $account->id,
            'post_target_id' => null,
            'platform' => $account->platform,
            'external_conversation_id' => (string) fake()->unique()->numerify('ig_comment_##########'),
            'author_name' => fake()->name(),
            'author_handle' => '@'.fake()->userName(),
            'author_avatar_url' => null,
            'permalink' => fake()->url(),
            'status' => InboxStatus::Open,
            'sentiment' => CommentSentiment::Neutral,
            'assigned_to' => null,
            'is_reply_needed' => false,
            'last_message_at' => now(),
            'last_inbound_at' => now(),
            'first_response_at' => null,
            'snoozed_until' => null,
            'resolved_at' => null,
            'resolved_by' => null,
        ];
    }

    public function forAccount(SocialAccount $account): static
    {
        return $this->state([
            'social_account_id' => $account->id,
            'client_id' => $account->client_id,
            'org_id' => $account->org_id,
            'platform' => $account->platform,
        ]);
    }

    public function needingReply(): static
    {
        return $this->state([
            'status' => InboxStatus::Open,
            'sentiment' => CommentSentiment::Question,
            'is_reply_needed' => true,
            'first_response_at' => null,
        ]);
    }

    public function resolved(): static
    {
        return $this->state([
            'status' => InboxStatus::Resolved,
            'is_reply_needed' => false,
            'resolved_at' => now(),
        ]);
    }
}
