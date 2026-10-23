<?php

namespace Tests\Feature\Business;

use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\Role;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Organization;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Step B-7.2 — the phone-first quick post flow end-to-end (server side).
 */
class QuickPostTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Organization, 1: User, 2: Client, 3: SocialAccount, 4: Asset}
     */
    private function workspace(bool $requireReview = false): array
    {
        $organization = Organization::factory()->business()->create([
            'require_post_review' => $requireReview,
            'default_timezone' => 'Europe/London',
        ]);
        $brand = Client::factory()->for($organization, 'organization')->primary()->create();
        $owner = User::factory()->for($organization, 'organization')->role(Role::Owner)->create();

        // LinkedIn is text-only-capable, so the readiness checklist passes with a caption + a time;
        // the attached image only makes the quick flow realistic.
        $account = SocialAccount::factory()->for($brand)->connected()->create([
            'org_id' => $organization->id,
            'platform' => Platform::LinkedIn,
            'timezone' => 'Europe/London',
        ]);

        $asset = Asset::factory()->for($brand)->create(['org_id' => $organization->id]);

        return [$organization, $owner, $brand, $account, $asset];
    }

    public function test_quick_post_page_loads_for_a_business_owner(): void
    {
        [, $owner, , $account] = $this->workspace();

        $this->actingAs($owner)
            ->get(route('business.posts.quick'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('business/posts/quick')
                ->has('accounts', 1)
                ->where('accounts.0.id', $account->id)
                ->has('suggestedSlot.date')
                ->where('timezone', 'Europe/London'));
    }

    public function test_it_creates_and_schedules_a_quick_post(): void
    {
        Queue::fake();
        [, $owner, $brand, $account, $asset] = $this->workspace();

        $this->actingAs($owner)
            ->post(route('business.posts.quick.store'), [
                'asset_id' => $asset->id,
                'caption' => 'Fresh out of the oven.',
                'account_ids' => [$account->id],
                'when' => 'schedule',
                'scheduled_local_date' => now()->addDay()->toDateString(),
                'scheduled_local_time' => '08:00',
            ])
            ->assertRedirect(route('business.today'));

        $post = $brand->posts()->sole();
        $this->assertSame(PostStatus::Scheduled, $post->status);
        $this->assertTrue($post->assets()->whereKey($asset->id)->exists());
        $this->assertSame(1, $post->targets()->count());
    }

    public function test_post_now_schedules_immediately(): void
    {
        Queue::fake();
        [, $owner, $brand, $account, $asset] = $this->workspace();

        $this->actingAs($owner)
            ->post(route('business.posts.quick.store'), [
                'asset_id' => $asset->id,
                'caption' => 'Live now.',
                'account_ids' => [$account->id],
                'when' => 'now',
            ])
            ->assertRedirect(route('business.today'));

        $post = $brand->posts()->sole();
        $this->assertSame(PostStatus::Scheduled, $post->status);
        // "Now" retimes the target to roughly the present moment.
        $this->assertTrue($post->targets()->sole()->scheduled_at_utc->diffInMinutes(now()) < 2);
    }

    public function test_review_on_sends_to_internal_review_instead_of_scheduling(): void
    {
        Queue::fake();
        [, $owner, $brand, $account, $asset] = $this->workspace(requireReview: true);

        $this->actingAs($owner)
            ->post(route('business.posts.quick.store'), [
                'asset_id' => $asset->id,
                'caption' => 'Please review.',
                'account_ids' => [$account->id],
                'when' => 'next',
            ])
            ->assertRedirect(route('business.today'));

        $this->assertSame(PostStatus::InternalReview, $brand->posts()->sole()->status);
    }

    public function test_it_rejects_an_asset_from_another_brand(): void
    {
        [, $owner, , $account] = $this->workspace();
        $otherAsset = Asset::factory()->create();

        $this->actingAs($owner)
            ->post(route('business.posts.quick.store'), [
                'asset_id' => $otherAsset->id,
                'caption' => 'Nope.',
                'account_ids' => [$account->id],
                'when' => 'now',
            ])
            ->assertSessionHasErrors('asset_id');
    }

    public function test_it_requires_a_caption_and_an_account(): void
    {
        [, $owner, , , $asset] = $this->workspace();

        $this->actingAs($owner)
            ->post(route('business.posts.quick.store'), [
                'asset_id' => $asset->id,
                'caption' => '',
                'account_ids' => [],
                'when' => 'now',
            ])
            ->assertSessionHasErrors(['caption', 'account_ids']);
    }

    public function test_a_viewer_cannot_quick_post(): void
    {
        [$organization, , $brand, $account, $asset] = $this->workspace();
        $viewer = User::factory()->for($organization, 'organization')->role(Role::Viewer)->create();

        $this->actingAs($viewer)
            ->post(route('business.posts.quick.store'), [
                'asset_id' => $asset->id,
                'caption' => 'Hi.',
                'account_ids' => [$account->id],
                'when' => 'now',
            ])
            ->assertForbidden();
    }

    public function test_an_agency_cannot_reach_the_quick_post_flow(): void
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->for($organization, 'organization')->role(Role::Owner)->create();

        $this->actingAs($owner)->get(route('business.posts.quick'))->assertNotFound();
    }
}
