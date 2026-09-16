<?php

namespace Tests\Unit\Services\Publishing;

use App\Contracts\PublishAdapter;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Services\Publishing\NullPublishAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class NullPublishAdapterTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_satisfies_the_publish_adapter_contract(): void
    {
        $this->assertInstanceOf(PublishAdapter::class, new NullPublishAdapter);
    }

    public function test_it_is_bound_as_the_default_publish_adapter(): void
    {
        $this->assertInstanceOf(NullPublishAdapter::class, app(PublishAdapter::class));
    }

    public function test_connect_account_does_not_throw(): void
    {
        $account = SocialAccount::factory()->create();

        $this->assertSame('', (new NullPublishAdapter)->connectAccount($account));
    }

    public function test_handle_callback_does_not_throw(): void
    {
        (new NullPublishAdapter)->handleCallback(['code' => 'irrelevant']);

        $this->assertTrue(true);
    }

    public function test_publish_returns_a_failed_target_result_per_target(): void
    {
        $post = Post::factory()->create();
        $targets = new Collection([
            SocialAccount::factory()->create(),
            SocialAccount::factory()->create(),
        ]);

        $results = (new NullPublishAdapter)->publish($post, $targets);

        $this->assertCount(2, $results);
        $results->each(function ($result): void {
            $this->assertFalse($result->ok);
            $this->assertNotNull($result->error);
        });
    }

    public function test_cancel_does_not_throw(): void
    {
        (new NullPublishAdapter)->cancel('external-post-id');

        $this->assertTrue(true);
    }

    public function test_health_reports_unhealthy(): void
    {
        $account = SocialAccount::factory()->create();

        $this->assertFalse((new NullPublishAdapter)->health($account));
    }
}
