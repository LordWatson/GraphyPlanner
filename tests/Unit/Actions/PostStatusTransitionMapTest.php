<?php

namespace Tests\Unit\Actions;

use App\Actions\Posts\PostStatusTransitionMap;
use App\Enums\PostStatus;
use PHPUnit\Framework\TestCase;

class PostStatusTransitionMapTest extends TestCase
{
    public function test_every_status_has_an_explicit_entry_in_the_map(): void
    {
        foreach (PostStatus::cases() as $status) {
            $this->assertArrayHasKey($status->value, PostStatusTransitionMap::MAP);
        }
    }

    public function test_valid_transitions_are_allowed(): void
    {
        $this->assertTrue(PostStatusTransitionMap::isAllowed(PostStatus::Draft, PostStatus::InternalReview));
        $this->assertTrue(PostStatusTransitionMap::isAllowed(PostStatus::WaitingClient, PostStatus::Approved));
        $this->assertTrue(PostStatusTransitionMap::isAllowed(PostStatus::WaitingClient, PostStatus::ChangesRequested));
    }

    public function test_invalid_transitions_are_rejected(): void
    {
        $this->assertFalse(PostStatusTransitionMap::isAllowed(PostStatus::Idea, PostStatus::Published));
        $this->assertFalse(PostStatusTransitionMap::isAllowed(PostStatus::Published, PostStatus::Draft));
        $this->assertFalse(PostStatusTransitionMap::isAllowed(PostStatus::Publishing, PostStatus::Archived));
    }

    public function test_terminal_published_status_can_only_be_archived(): void
    {
        $this->assertSame(
            [PostStatus::Archived],
            PostStatusTransitionMap::allowedFrom(PostStatus::Published),
        );
    }
}
