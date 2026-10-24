<?php

namespace Database\Factories;

use App\Enums\CommentSentiment;
use App\Enums\MessageDeliveryStatus;
use App\Enums\MessageDirection;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InboxMessage>
 */
class InboxMessageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $conversation = InboxConversation::factory()->create();

        return [
            'org_id' => $conversation->org_id,
            'inbox_conversation_id' => $conversation->id,
            'external_message_id' => (string) fake()->unique()->numerify('ig_comment_##########'),
            'parent_external_id' => null,
            'direction' => MessageDirection::Inbound,
            'author_name' => fake()->name(),
            'author_handle' => '@'.fake()->userName(),
            'body' => fake()->sentence(),
            'sentiment' => CommentSentiment::Neutral,
            'sent_by' => null,
            'vendor_comment_id' => null,
            'delivery_status' => null,
            'delivery_error' => null,
            'posted_at' => now(),
            'delivered_at' => null,
        ];
    }

    public function forConversation(InboxConversation $conversation): static
    {
        return $this->state([
            'inbox_conversation_id' => $conversation->id,
            'org_id' => $conversation->org_id,
        ]);
    }

    public function outbound(): static
    {
        return $this->state([
            'direction' => MessageDirection::Outbound,
            'sentiment' => null,
            'delivery_status' => MessageDeliveryStatus::Sent,
            'delivered_at' => now(),
            'author_name' => null,
            'author_handle' => null,
        ]);
    }
}
