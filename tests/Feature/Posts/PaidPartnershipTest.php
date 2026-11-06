<?php

namespace Tests\Feature\Posts;

use App\Actions\Posts\EvaluatePostChecklistAction;
use App\Enums\OrganizationType;
use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\Role;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Step CG-5.1 (B-11.1) — paid-partnership disclosure. The declaration is the owner's; the
 * pre-schedule checklist then refuses to let the post go out until every channel's **published**
 * caption actually says so, which is what makes the rule per-platform.
 */
class PaidPartnershipTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Organization, 1: Client, 2: User}
     */
    private function creatorBrand(): array
    {
        $org = Organization::factory()->create(['type' => OrganizationType::Business, 'plan' => 'creator_pro']);
        $brand = Client::factory()->for($org, 'organization')->primary()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();

        return [$org, $brand, $owner];
    }

    /**
     * A post that passes every other hard check, on the given platform.
     */
    private function readyPost(Client $brand, Platform $platform, array $overrides = []): Post
    {
        $account = SocialAccount::factory()->for($brand)->create([
            'org_id' => $brand->org_id,
            'platform' => $platform,
            'timezone' => 'Europe/London',
            'platform_settings' => $platform === Platform::Pinterest ? ['pinterest_board_id' => 'board-1'] : [],
        ]);

        $post = Post::factory()->for($brand)->create([
            'org_id' => $brand->org_id,
            'status' => PostStatus::Approved,
            'master_caption' => 'New loaf out today.',
            ...$overrides,
        ]);

        $post->assets()->attach(Asset::factory()->for($brand)->create(['org_id' => $brand->org_id])->id);

        $post->targets()->create([
            'social_account_id' => $account->id,
            'scheduled_local_date' => now()->addDay()->toDateString(),
            'scheduled_local_time' => '10:00',
            'scheduled_at_utc' => now()->addDay()->setTime(10, 0),
        ]);

        return $post->refresh();
    }

    private function disclosureItem(Post $post): array
    {
        $checklist = (new EvaluatePostChecklistAction)($post);
        $item = collect($checklist['items'])->firstWhere('key', 'partnership_disclosure');

        $this->assertNotNull($item, 'The checklist always carries the disclosure item.');

        return [$item, $checklist['passed']];
    }

    public function test_an_ordinary_post_is_never_asked_to_disclose(): void
    {
        [, $brand] = $this->creatorBrand();
        $post = $this->readyPost($brand, Platform::Instagram);

        [$item, $passed] = $this->disclosureItem($post);

        $this->assertTrue($item['passed']);
        $this->assertTrue($passed);
    }

    public function test_a_paid_partnership_without_a_disclosure_cannot_be_scheduled(): void
    {
        [, $brand, $owner] = $this->creatorBrand();
        $post = $this->readyPost($brand, Platform::Instagram, ['is_paid_partnership' => true]);

        [$item, $passed] = $this->disclosureItem($post);

        $this->assertFalse($item['passed']);
        $this->assertFalse($item['soft'] ?? false, 'Disclosure is a blocker, not a warning.');
        $this->assertFalse($passed);
        $this->assertStringContainsString('#ad', (string) $item['detail']);

        // And the transition endpoint refuses it on the same grounds.
        $this->actingAs($owner)
            ->post(route('posts.transition', $post), ['to' => PostStatus::Scheduled->value])
            ->assertSessionHasErrors('to');

        $this->assertSame(PostStatus::Approved, $post->refresh()->status);
    }

    public function test_a_disclosure_in_the_caption_or_the_hashtags_passes(): void
    {
        [, $brand] = $this->creatorBrand();

        $inCaption = $this->readyPost($brand, Platform::Instagram, [
            'is_paid_partnership' => true,
            'master_caption' => 'New loaf out today. Paid partnership with Yorkshire Tea.',
        ]);
        [$item] = $this->disclosureItem($inCaption);
        $this->assertTrue($item['passed']);

        $inHashtags = $this->readyPost($brand, Platform::Instagram, [
            'is_paid_partnership' => true,
            'hashtags' => ['sourdough', 'ad'],
        ]);
        [$item, $passed] = $this->disclosureItem($inHashtags);
        $this->assertTrue($item['passed'], 'Instagram takes hashtags, so #ad in them is published.');
        $this->assertTrue($passed);
    }

    public function test_a_hashtag_only_disclosure_fails_where_the_platform_strips_hashtags(): void
    {
        [, $brand] = $this->creatorBrand();

        // Google Business publishes no hashtags, so `#ad` in the hashtag field never reaches a reader.
        $post = $this->readyPost($brand, Platform::GoogleBusiness, [
            'is_paid_partnership' => true,
            'hashtags' => ['ad'],
        ]);

        [$item] = $this->disclosureItem($post);
        $this->assertFalse($item['passed']);
        $this->assertStringContainsString('strips hashtags', (string) $item['detail']);

        // Written into the caption, it passes there too.
        $post->update(['master_caption' => 'Open late tonight. Paid promotion.']);
        [$item] = $this->disclosureItem($post->refresh());
        $this->assertTrue($item['passed']);
    }

    public function test_every_channel_has_to_disclose_not_just_one(): void
    {
        [, $brand] = $this->creatorBrand();
        $post = $this->readyPost($brand, Platform::Instagram, ['is_paid_partnership' => true]);

        $second = SocialAccount::factory()->for($brand)->create([
            'org_id' => $brand->org_id,
            'platform' => Platform::Facebook,
            'timezone' => 'Europe/London',
        ]);
        $post->targets()->create([
            'social_account_id' => $second->id,
            'scheduled_local_date' => now()->addDay()->toDateString(),
            'scheduled_local_time' => '10:00',
            'scheduled_at_utc' => now()->addDay()->setTime(10, 0),
            // Only this channel's own caption discloses.
            'caption_override' => 'New loaf out today. #ad',
        ]);

        [$item] = $this->disclosureItem($post->refresh());

        $this->assertFalse($item['passed']);
        $this->assertStringContainsString('Instagram', (string) $item['detail']);
        $this->assertStringNotContainsString('Facebook', (string) $item['detail']);
    }

    public function test_the_owner_saves_the_partnership_fields(): void
    {
        [, $brand, $owner] = $this->creatorBrand();
        $post = $this->readyPost($brand, Platform::Instagram);

        $this->actingAs($owner)->put(route('posts.update', $post), [
            'master_caption' => 'New loaf out today. #ad',
            'is_paid_partnership' => true,
            'partner_name' => 'Yorkshire Tea',
            'partner_handle' => '@yorkshiretea',
        ])->assertRedirect();

        $post->refresh();
        $this->assertTrue($post->is_paid_partnership);
        $this->assertSame('Yorkshire Tea', $post->partner_name);
        $this->assertSame('@yorkshiretea', $post->partner_handle);
        $this->assertTrue($post->checklist_snapshot['passed']);
    }
}
