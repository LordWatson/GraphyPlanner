<?php

namespace Tests\Feature\Business;

use App\Actions\Business\GetBioInsightsAction;
use App\Enums\OrganizationType;
use App\Enums\PostStatus;
use App\Enums\Role;
use App\Models\BioBlock;
use App\Models\BioPage;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase CG-3 (B-11.3) — the link in bio: the editor's one-shot save, the public page at
 * `/@{handle}`, and the click/view rollups behind the insights section.
 */
class BioPageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Organization, 1: Client, 2: User}
     */
    private function creatorBrand(string $plan = 'creator_pro'): array
    {
        $org = Organization::factory()->create(['type' => OrganizationType::Business, 'plan' => $plan]);
        $brand = Client::factory()->for($org, 'organization')->primary()->create(['name' => 'Jess Bakery']);
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();

        return [$org, $brand, $owner];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'handle' => 'jessbakery',
            'title' => 'Jess Bakery',
            'bio' => 'Sourdough in Leeds.',
            'theme' => 'cream',
            'is_published' => true,
            'blocks' => [
                ['type' => 'link', 'is_visible' => true, 'settings' => ['label' => 'Order', 'url' => 'https://jessbakery.test/order']],
            ],
            ...$overrides,
        ];
    }

    public function test_the_owner_saves_and_publishes_a_page(): void
    {
        [, $brand, $owner] = $this->creatorBrand();

        $this->actingAs($owner)->put(route('business.bio.update'), $this->payload())->assertRedirect();

        $page = BioPage::query()->where('client_id', $brand->id)->firstOrFail();
        $this->assertSame('jessbakery', $page->handle);
        $this->assertTrue($page->isPublished());
        $this->assertCount(1, $page->blocks);

        $this->get('/@jessbakery')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('public/bio')
                ->where('page.title', 'Jess Bakery')
                ->where('page.blocks.0.label', 'Order'));
    }

    public function test_the_handle_is_unique_and_reserved_words_are_refused(): void
    {
        [, , $owner] = $this->creatorBrand();
        [, $otherBrand] = $this->creatorBrand();

        BioPage::create([
            'org_id' => $otherBrand->org_id,
            'client_id' => $otherBrand->id,
            'handle' => 'taken',
            'theme' => 'cream',
        ]);

        $this->actingAs($owner)
            ->put(route('business.bio.update'), $this->payload(['handle' => 'taken']))
            ->assertSessionHasErrors('handle');

        $this->actingAs($owner)
            ->put(route('business.bio.update'), $this->payload(['handle' => 'admin']))
            ->assertSessionHasErrors('handle');

        // Uppercase and padding are normalised rather than refused.
        $this->actingAs($owner)
            ->put(route('business.bio.update'), $this->payload(['handle' => '  JessBakery ']))
            ->assertSessionHasNoErrors();

        $this->assertSame('jessbakery', BioPage::query()->where('org_id', '!=', $otherBrand->org_id)->value('handle'));
    }

    public function test_an_unpublished_page_and_a_deleted_brand_both_404(): void
    {
        [, $brand, $owner] = $this->creatorBrand();

        $this->actingAs($owner)
            ->put(route('business.bio.update'), $this->payload(['is_published' => false]))
            ->assertRedirect();

        $this->get('/@jessbakery')->assertNotFound();

        // Publish, then delete the brand: the page goes with it.
        $this->actingAs($owner)->put(route('business.bio.update'), $this->payload());
        $this->get('/@jessbakery')->assertOk();

        $brand->forceDelete();
        $this->get('/@jessbakery')->assertNotFound();
    }

    public function test_hidden_blocks_and_unpublished_posts_never_render(): void
    {
        [, $brand, $owner] = $this->creatorBrand();

        Post::factory()->for($brand)->create([
            'org_id' => $brand->org_id,
            'status' => PostStatus::Draft,
            'master_caption' => 'SECRET unpublished idea',
        ]);

        $this->actingAs($owner)->put(route('business.bio.update'), $this->payload(['blocks' => [
            ['type' => 'link', 'is_visible' => true, 'settings' => ['label' => 'Shown', 'url' => 'https://example.test/a']],
            ['type' => 'link', 'is_visible' => false, 'settings' => ['label' => 'Hidden', 'url' => 'https://example.test/b']],
            ['type' => 'latest_posts', 'is_visible' => true, 'settings' => ['limit' => 3]],
            // The media kit's public link is off, so this block has nothing to point at.
            ['type' => 'media_kit', 'is_visible' => true, 'settings' => []],
        ]]));

        $body = $this->get('/@jessbakery')->assertOk()->getContent();

        $this->assertStringContainsString('Shown', $body);
        $this->assertStringNotContainsString('Hidden', $body);
        $this->assertStringNotContainsString('SECRET unpublished idea', $body);
        $this->assertStringNotContainsString('media kit', $body);
    }

    public function test_one_save_adds_edits_reorders_hides_and_removes_blocks(): void
    {
        [, $brand, $owner] = $this->creatorBrand();

        $this->actingAs($owner)->put(route('business.bio.update'), $this->payload(['blocks' => [
            ['type' => 'link', 'is_visible' => true, 'settings' => ['label' => 'First', 'url' => 'https://example.test/1']],
            ['type' => 'link', 'is_visible' => true, 'settings' => ['label' => 'Second', 'url' => 'https://example.test/2']],
            ['type' => 'link', 'is_visible' => true, 'settings' => ['label' => 'Third', 'url' => 'https://example.test/3']],
        ]]));

        $page = BioPage::query()->where('client_id', $brand->id)->firstOrFail();
        $blocks = $page->blocks()->get();
        [$first, $second] = [$blocks[0], $blocks[1]];

        // Swap the first two, hide the second, rename the first, drop the third, add a new one.
        $this->actingAs($owner)->put(route('business.bio.update'), $this->payload(['blocks' => [
            ['id' => $second->id, 'type' => 'link', 'is_visible' => false, 'settings' => ['label' => 'Second', 'url' => 'https://example.test/2']],
            ['id' => $first->id, 'type' => 'link', 'is_visible' => true, 'settings' => ['label' => 'Renamed', 'url' => 'https://example.test/1']],
            ['type' => 'media_kit', 'is_visible' => true, 'settings' => ['label' => 'My kit']],
        ]]))->assertSessionHasNoErrors();

        $blocks = $page->blocks()->get();
        $this->assertCount(3, $blocks);
        $this->assertSame($second->id, $blocks[0]->id);
        $this->assertFalse($blocks[0]->is_visible);
        $this->assertSame('Renamed', $blocks[1]->settings['label']);
        $this->assertSame('media_kit', $blocks[2]->type->value);
        $this->assertSame([0, 1, 2], $blocks->pluck('position')->all());
    }

    public function test_a_block_id_from_another_brand_is_never_touched(): void
    {
        [, , $owner] = $this->creatorBrand();
        [, $otherBrand] = $this->creatorBrand();

        $theirPage = BioPage::create([
            'org_id' => $otherBrand->org_id,
            'client_id' => $otherBrand->id,
            'handle' => 'theirs',
            'theme' => 'cream',
        ]);
        $theirBlock = $theirPage->blocks()->create([
            'type' => 'link',
            'position' => 0,
            'settings' => ['label' => 'Theirs', 'url' => 'https://theirs.test'],
            'is_visible' => true,
        ]);

        $this->actingAs($owner)->put(route('business.bio.update'), $this->payload(['blocks' => [
            ['id' => $theirBlock->id, 'type' => 'link', 'is_visible' => true, 'settings' => ['label' => 'Stolen', 'url' => 'https://evil.test']],
        ]]))->assertSessionHasNoErrors();

        $this->assertSame('Theirs', $theirBlock->refresh()->settings['label']);
        $this->assertSame(1, BioBlock::query()->where('bio_page_id', $theirPage->id)->count());
    }

    public function test_publishing_is_a_plan_gate_but_drafting_is_not(): void
    {
        [, $brand, $owner] = $this->creatorBrand('business_free');

        $this->actingAs($owner)
            ->put(route('business.bio.update'), $this->payload(['is_published' => true]))
            ->assertRedirect();

        // The upgrade prompt is a friendly redirect, not a save.
        $this->assertNull(BioPage::query()->where('client_id', $brand->id)->first());

        $this->actingAs($owner)
            ->put(route('business.bio.update'), $this->payload(['is_published' => false]))
            ->assertRedirect();

        $this->assertNotNull(BioPage::query()->where('client_id', $brand->id)->first());
    }

    public function test_an_agency_cannot_reach_the_editor(): void
    {
        [, , $owner] = $this->creatorBrand();
        $this->actingAs($owner)->get(route('business.bio.edit'))->assertOk();

        $agencyOwner = User::factory()
            ->for(Organization::factory()->create(), 'organization')
            ->role(Role::Owner)
            ->create();

        $this->actingAs($agencyOwner)->get(route('business.bio.edit'))->assertNotFound();
    }

    /**
     * Regression: a page prop named `preview` shadows the shared preview-mode boolean, which puts
     * the whole shell into the read-only `/preview/*` mirror — every link on the page is then
     * cancelled with "That page isn't part of the preview." The owner's live preview payload must
     * therefore never be called `preview`.
     */
    public function test_the_editor_never_shadows_the_shared_preview_prop(): void
    {
        [, , $owner] = $this->creatorBrand();

        foreach (['business.bio.edit', 'business.media-kit.index'] as $route) {
            $this->actingAs($owner)
                ->get(route($route))
                ->assertOk()
                ->assertInertia(fn ($page) => $page->where('preview', false));
        }
    }

    public function test_a_click_redirects_to_the_owners_url_and_is_counted(): void
    {
        [, $brand, $owner] = $this->creatorBrand();
        $this->actingAs($owner)->put(route('business.bio.update'), $this->payload());

        $page = BioPage::query()->where('client_id', $brand->id)->firstOrFail();
        $block = $page->blocks()->firstOrFail();

        $this->visitor()->get("/@jessbakery/go/{$block->id}")
            ->assertRedirect('https://jessbakery.test/order');

        $this->assertSame(1, $this->counted($page->id, $block->id));

        // A second click rolls up into the same day's row.
        $this->visitor()->get("/@jessbakery/go/{$block->id}");
        $this->assertSame(2, $this->counted($page->id, $block->id));

        // A block that isn't this page's, and one that's hidden, both 404.
        $this->visitor()->get('/@jessbakery/go/999999')->assertNotFound();
        $block->update(['is_visible' => false]);
        $this->visitor()->get("/@jessbakery/go/{$block->id}")->assertNotFound();
    }

    public function test_views_are_counted_and_bots_and_the_owner_are_not(): void
    {
        [, $brand, $owner] = $this->creatorBrand();
        $this->actingAs($owner)->put(route('business.bio.update'), $this->payload());
        $page = BioPage::query()->where('client_id', $brand->id)->firstOrFail();

        $this->visitor()->get('/@jessbakery')->assertOk();
        $this->assertSame(1, $this->counted($page->id, null));

        // A crawler / link unfurler, and a request with no user agent at all.
        $this->visitor()->withHeader('User-Agent', 'Mozilla/5.0 (compatible; Googlebot/2.1)')->get('/@jessbakery');
        $this->visitor()->withHeader('User-Agent', 'WhatsApp/2.23')->get('/@jessbakery');
        $this->visitor()->withHeader('User-Agent', '')->get('/@jessbakery');

        // The owner checking their own page.
        $this->actingAs($owner)->get('/@jessbakery')->assertOk();

        $this->assertSame(1, $this->counted($page->id, null));
    }

    public function test_the_insights_section_renders_with_and_without_data(): void
    {
        [, $brand, $owner] = $this->creatorBrand();

        // No page yet: the section is absent rather than zeroed.
        $this->actingAs($owner)
            ->get(route('business.insights.index'), ['X-Inertia-Partial-Data' => 'bio'])
            ->assertOk();

        $this->actingAs($owner)->put(route('business.bio.update'), $this->payload());
        $page = BioPage::query()->where('client_id', $brand->id)->firstOrFail();
        $block = $page->blocks()->firstOrFail();

        $this->visitor()->get('/@jessbakery');
        $this->visitor()->get("/@jessbakery/go/{$block->id}");

        $bio = app(GetBioInsightsAction::class)($brand);

        $this->assertSame('jessbakery', $bio['handle']);
        $this->assertSame(1, $bio['views']);
        $this->assertSame(1, $bio['clicks']);
        $this->assertSame([['label' => 'Order', 'clicks' => 1]], $bio['top_links']);
    }

    /**
     * A real visitor: signed out (so the owner-exclusion rule doesn't apply) with a browser user
     * agent (so the bot rule doesn't).
     */
    private function visitor(): static
    {
        $this->app->make('auth')->forgetGuards();

        return $this->withHeader('User-Agent', 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0) Safari/605.1');
    }

    private function counted(int $pageId, ?int $blockId): int
    {
        return (int) DB::table('bio_events')
            ->where('bio_page_id', $pageId)
            ->when($blockId === null, fn ($query) => $query->whereNull('bio_block_id'))
            ->when($blockId !== null, fn ($query) => $query->where('bio_block_id', $blockId))
            ->sum('count');
    }
}
