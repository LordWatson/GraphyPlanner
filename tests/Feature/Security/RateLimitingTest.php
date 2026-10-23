<?php

namespace Tests\Feature\Security;

use App\Enums\Role;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Step I-1.7 — every named limiter trips at its threshold with the structured 429, and the AI
 * endpoints no longer answer GET.
 */
class RateLimitingTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $owner;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        // Exports / org settings sit behind `password.confirm` (Step M-1.2); not under test here.
        $this->confirmPassword();

        Config::set('ai.enabled', true);

        $this->org = Organization::factory()->create();
        $this->owner = User::factory()->for($this->org, 'organization')->role(Role::Owner)->create();
        $this->client = Client::factory()->for($this->org, 'organization')->create();
    }

    public function test_ai_endpoints_no_longer_answer_get(): void
    {
        $post = Post::factory()->for($this->client)->create(['org_id' => $this->org->id]);

        $this->actingAs($this->owner)->getJson('/clients/'.$this->client->id.'/ai/trends')->assertStatus(405);
        $this->actingAs($this->owner)->getJson('/clients/'.$this->client->id.'/ai/ideas')->assertStatus(405);
        $this->actingAs($this->owner)->getJson('/posts/'.$post->id.'/ai/captions')->assertStatus(405);
    }

    public function test_the_ai_limiter_trips_after_ten_calls_a_minute_with_a_structured_429(): void
    {
        // Step M-7.2: the AI endpoints now queue a job and answer 202 (accepted) before the limiter.
        $this->assertTripsAfter(10, fn () => $this->actingAs($this->owner)->postJson(route('clients.ai.ideas', $this->client)), expectedStatus: 202)
            ->assertJsonStructure(['message', 'retry_after'])
            ->assertJsonPath('message', fn (string $message) => str_contains($message, 'AI limit'));
    }

    public function test_the_ai_limiter_is_also_capped_per_organization(): void
    {
        $limits = RateLimiter::limiter('ai')(Request::create('/')->setUserResolver(fn () => $this->owner));

        $this->assertCount(2, $limits);
        $this->assertSame(10, $limits[0]->maxAttempts);
        $this->assertSame(60, $limits[1]->maxAttempts);
        $this->assertStringContainsString('org:'.$this->org->id, $limits[1]->key);
    }

    public function test_the_ai_images_limiter_trips_after_six_calls(): void
    {
        $limits = RateLimiter::limiter('ai-images')(Request::create('/')->setUserResolver(fn () => $this->owner));

        $this->assertSame(6, $limits[0]->maxAttempts);
        $this->assertSame(30, $limits[1]->maxAttempts);
    }

    public function test_the_music_search_limiter_trips_after_thirty_calls(): void
    {
        $post = Post::factory()->for($this->client)->create(['org_id' => $this->org->id]);

        $this->assertTripsAfter(30, fn () => $this->actingAs($this->owner)
            ->getJson(route('posts.music-search', ['post' => $post, 'query' => 'song', 'platform' => 'linkedin'])));
    }

    public function test_the_exports_limiter_trips_after_ten_exports(): void
    {
        $this->assertTripsAfter(10, fn () => $this->actingAs($this->owner)->get(route('invoices.export')));
    }

    public function test_the_public_token_limiter_trips_per_ip_and_token(): void
    {
        $this->assertTripsAfter(20, fn () => $this->get(route('review.show', 'guessed-token')), expectedStatus: 404);

        // A different token from the same address still has room under the per-IP cap…
        $this->get(route('review.show', 'another-guess'))->assertNotFound();
    }

    public function test_the_public_token_limiter_caps_an_address_across_tokens(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->get(route('client-invite.show', "guess-{$i}"));
        }

        $this->get(route('staff-invite.show', 'yet-another-guess'))->assertStatus(429);
    }

    public function test_the_webhook_limiter_trips_after_120_calls(): void
    {
        $this->assertTripsAfter(120, fn () => $this->postJson(route('webhooks.upload-post'), []), expectedStatus: null);
    }

    /**
     * Fire `$allowed` requests (each must not be a 429), then one more that must be.
     */
    private function assertTripsAfter(int $allowed, callable $request, ?int $expectedStatus = 200): TestResponse
    {
        for ($i = 1; $i <= $allowed; $i++) {
            $response = $request();
            $this->assertNotSame(429, $response->getStatusCode(), "Request {$i} of {$allowed} was throttled.");

            if ($expectedStatus !== null) {
                $response->assertStatus($expectedStatus);
            }
        }

        return $request()->assertStatus(429)->assertHeader('Retry-After');
    }
}
