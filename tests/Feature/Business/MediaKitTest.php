<?php

namespace Tests\Feature\Business;

use App\Actions\Business\BuildMediaKitAction;
use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\Role;
use App\Models\Client;
use App\Models\MediaKit;
use App\Models\Organization;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Analytics\Concerns\BuildsEngagementFixtures;
use Tests\TestCase;

/**
 * Step B-8.4 — the creator/business media kit: opt-in public link, revocable, built only from
 * already-public data (published posts, public follower counts — never emails or drafts).
 */
class MediaKitTest extends TestCase
{
    use BuildsEngagementFixtures, RefreshDatabase;

    /**
     * @return array{0: Organization, 1: Client, 2: User}
     */
    private function creatorBrand(): array
    {
        $org = Organization::factory()->business()->create(['default_timezone' => 'Europe/London']);
        $brand = Client::factory()->for($org, 'organization')->primary()->create([
            'name' => 'Jess Bakery',
            'website' => 'jessbakery.test',
            'location' => 'Leeds, UK',
        ]);
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create([
            'email' => 'owner-secret@example.test',
        ]);

        return [$org, $brand, $owner];
    }

    public function test_it_builds_from_public_data_only(): void
    {
        [, $brand, $owner] = $this->creatorBrand();
        $account = $this->engagementAccount($brand, Platform::Instagram, 'Europe/London');

        $live = $this->publishedPost($brand, [$account], now()->subDays(3), ['master_caption' => 'LIVE sourdough reel']);
        $this->reading($live, $account, ['impressions' => 900, 'reach' => 700, 'likes' => 60, 'comments' => 12]);
        $this->followersOn($account, now()->subDays(10)->toDateString(), 2000);
        $this->followersOn($account, now()->subDay()->toDateString(), 2200);

        // A draft that must never surface on the kit.
        Post::factory()->for($brand)->create([
            'org_id' => $brand->org_id,
            'status' => PostStatus::Draft,
            'master_caption' => 'SECRET unpublished idea',
        ]);

        $build = app(BuildMediaKitAction::class);
        $kit = $build->kitFor($brand);
        $data = $build($kit, $brand);

        $this->assertSame('Jess Bakery', $data['brand']['name']);
        $this->assertSame(2200, $data['audience']['total_followers']);
        $this->assertSame(700, $data['engagement']['total_reach']);
        $this->assertNotEmpty($data['top_posts']);
        $this->assertSame([], $data['partners']);

        $encoded = (string) json_encode($data);
        $this->assertStringContainsString('LIVE sourdough reel', $encoded);
        $this->assertStringNotContainsString('SECRET unpublished idea', $encoded);
        $this->assertStringNotContainsString($owner->email, $encoded);
    }

    public function test_the_public_link_is_opt_in_and_404s_until_shared(): void
    {
        [, $brand] = $this->creatorBrand();
        $kit = app(BuildMediaKitAction::class)->kitFor($brand);

        // No token yet — a guessed URL must not resolve.
        $this->get('/kit/not-a-real-token')->assertNotFound();
        $this->assertFalse($kit->isPublic());
    }

    public function test_sharing_then_revoking_toggles_the_public_page(): void
    {
        [, $brand, $owner] = $this->creatorBrand();

        $this->actingAs($owner)->post(route('business.media-kit.share'))->assertRedirect();
        $kit = MediaKit::query()->where('client_id', $brand->id)->firstOrFail();
        $this->assertTrue($kit->isPublic());

        $this->get(route('media-kit.show', $kit->share_token))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('share/media-kit')->where('kit.brand.name', 'Jess Bakery'));

        $token = $kit->share_token;

        $this->actingAs($owner)->delete(route('business.media-kit.revoke'))->assertRedirect();
        $this->get(route('media-kit.show', $token))->assertNotFound();
    }

    public function test_generating_a_new_link_invalidates_the_old_one(): void
    {
        [, $brand, $owner] = $this->creatorBrand();

        $this->actingAs($owner)->post(route('business.media-kit.share'));
        $first = MediaKit::query()->where('client_id', $brand->id)->firstOrFail()->share_token;

        $this->actingAs($owner)->post(route('business.media-kit.share'));
        $second = MediaKit::query()->where('client_id', $brand->id)->firstOrFail()->share_token;

        $this->assertNotSame($first, $second);
        $this->get(route('media-kit.show', $first))->assertNotFound();
        $this->get(route('media-kit.show', $second))->assertOk();
    }

    public function test_the_owner_page_loads_and_an_agency_cannot_reach_it(): void
    {
        [, , $owner] = $this->creatorBrand();

        $this->actingAs($owner)
            ->get(route('business.media-kit.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('business/media-kit')->where('kit.is_public', false));

        $agencyOwner = User::factory()
            ->for(Organization::factory()->create(), 'organization')
            ->role(Role::Owner)
            ->create();

        $this->actingAs($agencyOwner)->get(route('business.media-kit.index'))->assertNotFound();
    }

    public function test_bio_is_saved_and_appears_on_the_kit(): void
    {
        [, $brand, $owner] = $this->creatorBrand();

        $this->actingAs($owner)
            ->put(route('business.media-kit.update'), ['bio' => '  Artisan bakery in Leeds.  '])
            ->assertRedirect();

        $this->assertSame('Artisan bakery in Leeds.', MediaKit::query()->where('client_id', $brand->id)->value('bio'));
    }

    public function test_the_pdf_downloads_for_the_owner(): void
    {
        [, , $owner] = $this->creatorBrand();

        $response = $this->actingAs($owner)->get(route('business.media-kit.pdf'));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
    }
}
