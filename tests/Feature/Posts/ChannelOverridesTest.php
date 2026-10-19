<?php

namespace Tests\Feature\Posts;

use App\Actions\Posts\EvaluatePostChecklistAction;
use App\Actions\ReviewTokens\CreateReviewTokenAction;
use App\Enums\AssetType;
use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\Role;
use App\Http\Requests\UpdatePostRequest;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Publishing\UploadPostAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Step I-3.1 / M-2.2 — one post, a different caption, first comment and link per channel.
 */
class ChannelOverridesTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Client $client;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['upload_post_key' => 'key']);
        $this->client = Client::factory()->for($this->org, 'organization')->create();
        $this->owner = User::factory()->for($this->org, 'organization')->role(Role::Owner)->create();
    }

    private function account(Platform $platform, string $timezone = 'Europe/London', array $attributes = []): SocialAccount
    {
        return SocialAccount::factory()->for($this->client)->create([
            'org_id' => $this->org->id,
            'platform' => $platform,
            'timezone' => $timezone,
            'handle' => '@'.$platform->value,
            ...$attributes,
        ]);
    }

    private function makePost(array $attributes = []): Post
    {
        return Post::factory()->for($this->client)->create([
            'org_id' => $this->org->id,
            'master_caption' => 'Brunch is back.',
            'hashtags' => ['brunch'],
            ...$attributes,
        ]);
    }

    private function image(array $attributes = []): Asset
    {
        return Asset::factory()->for($this->client)->create([
            'org_id' => $this->org->id,
            'type' => AssetType::Image,
            'url' => 'https://cdn.test/'.fake()->uuid().'.jpg',
            'disk' => null,
            'path' => null,
            'width' => 1080,
            'height' => 1350,
            ...$attributes,
        ]);
    }

    /**
     * @return array<string, array<int, string>>
     */
    private static function fields(Request $request): array
    {
        $fields = [];

        foreach ($request->data() as $name => $part) {
            if (is_array($part) && isset($part['name'])) {
                $fields[$part['name']][] = (string) $part['contents'];
            } else {
                $fields[(string) $name][] = is_array($part) ? json_encode($part) : (string) $part;
            }
        }

        return $fields;
    }

    public function test_london_dubai_acceptance_each_vendor_payload_carries_its_own_caption_and_first_comment(): void
    {
        Http::fake(['*/upload_photos' => Http::response(['request_id' => 'req-1'])]);

        $dubaiInstagram = $this->account(Platform::Instagram, 'Asia/Dubai');
        $londonLinkedIn = $this->account(Platform::LinkedIn, 'Europe/London');
        $post = $this->makePost(['first_comment' => 'Book at alima.test/brunch']);
        $post->assets()->attach($this->image(), ['alt_text' => 'Eggs benedict on a terrace']);

        $post->targets()->create([
            'social_account_id' => $dubaiInstagram->id,
            'caption_override' => 'Brunch is back in Dubai Marina.',
            'hashtags_override' => ['dubaibrunch', 'dxb'],
            'first_comment' => 'Link in bio to book.',
        ]);
        $post->targets()->create(['social_account_id' => $londonLinkedIn->id]);

        $post->load('targets.socialAccount', 'assets');
        $spacer = implode("\n", array_fill(0, 8, '-'));

        (new UploadPostAdapter)->publish($post, collect([$dubaiInstagram, $londonLinkedIn]));

        Http::assertSent(function (Request $request) use ($spacer) {
            $fields = self::fields($request);

            return ($fields['platform[]'] ?? null) === ['instagram']
                && $fields['instagram_title'] === ["Brunch is back in Dubai Marina.\n{$spacer}\n#dubaibrunch #dxb"]
                && $fields['instagram_first_comment'] === ['Link in bio to book.']
                && $fields['instagram_alt_text'] === ['["Eggs benedict on a terrace"]']
                && ! array_key_exists('description', $fields);
        });

        Http::assertSent(function (Request $request) use ($spacer) {
            $fields = self::fields($request);

            return ($fields['platform[]'] ?? null) === ['linkedin']
                && $fields['linkedin_title'] === ["Brunch is back.\n{$spacer}\n#brunch"]
                && $fields['description'] === ["Brunch is back.\n{$spacer}\n#brunch"]
                && $fields['linkedin_first_comment'] === ['Book at alima.test/brunch'];
        });
    }

    public function test_a_link_goes_in_the_caption_where_links_work_and_in_its_own_field_on_pinterest(): void
    {
        Http::fake(['*/upload_photos' => Http::response(['request_id' => 'req-1'])]);

        $linkedIn = $this->account(Platform::LinkedIn);
        $pinterest = $this->account(Platform::Pinterest, attributes: ['platform_settings' => ['pinterest_board_id' => 'board-9']]);
        $post = $this->makePost(['hashtags' => [], 'link_url' => 'https://alima.test/menu']);
        $post->assets()->attach($this->image());
        $post->targets()->create(['social_account_id' => $linkedIn->id]);
        $post->targets()->create(['social_account_id' => $pinterest->id]);
        $post->load('targets.socialAccount', 'assets');

        (new UploadPostAdapter)->publish($post, collect([$linkedIn, $pinterest]));

        Http::assertSent(fn (Request $request) => (self::fields($request)['platform[]'] ?? null) === ['linkedin']
            && self::fields($request)['linkedin_title'] === ["Brunch is back.\n\nhttps://alima.test/menu"]);

        Http::assertSent(fn (Request $request) => (self::fields($request)['platform[]'] ?? null) === ['pinterest']
            && self::fields($request)['pinterest_link'] === ['https://alima.test/menu']
            && self::fields($request)['pinterest_board_id'] === ['board-9']
            && self::fields($request)['pinterest_title'] === ['Brunch is back.']
            && ! str_contains(self::fields($request)['description'][0], 'https://'));
    }

    public function test_saving_keeps_each_channels_overrides_status_and_id_and_supports_none_and_reset(): void
    {
        $instagram = $this->account(Platform::Instagram);
        $linkedIn = $this->account(Platform::LinkedIn);
        $post = $this->makePost();
        $target = $post->targets()->create(['social_account_id' => $instagram->id, 'caption_override' => 'Old', 'external_post_id' => 'keep-me']);

        $this->actingAs($this->owner)->put(route('posts.update', $post), [
            'master_caption' => 'Brunch is back.',
            'targets' => [
                [
                    'social_account_id' => $instagram->id,
                    'scheduled_local_date' => '2030-01-01',
                    'scheduled_local_time' => '09:00',
                    'caption_override' => 'Instagram only',
                    'hashtags_override' => '[]',
                    'first_comment' => UpdatePostRequest::EMPTY_OVERRIDE,
                    'format' => 'feed',
                ],
                [
                    'social_account_id' => $linkedIn->id,
                    'scheduled_local_date' => '2030-01-01',
                    'scheduled_local_time' => '10:00',
                    'link_url' => 'https://alima.test',
                ],
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $target->refresh();
        $this->assertSame('Instagram only', $target->caption_override);
        $this->assertSame([], $target->hashtags_override);
        $this->assertSame('', $target->first_comment);
        $this->assertSame('feed', $target->format);
        $this->assertSame('keep-me', $target->external_post_id, 'An existing channel is updated in place.');
        $this->assertSame([], $target->effectiveHashtags());
        $this->assertNull($target->effectiveFirstComment());

        $linkedInTarget = $post->targets()->where('social_account_id', $linkedIn->id)->firstOrFail();
        $this->assertNull($linkedInTarget->caption_override);
        $this->assertSame('https://alima.test', $linkedInTarget->link_url);

        // Sending null resets a channel to the master.
        $this->actingAs($this->owner)->put(route('posts.update', $post), [
            'targets' => [['social_account_id' => $instagram->id, 'caption_override' => null, 'hashtags_override' => null]],
        ])->assertSessionHasNoErrors();

        $target->refresh();
        $this->assertNull($target->caption_override);
        $this->assertNull($target->hashtags_override);
        $this->assertSame(['brunch'], $target->effectiveHashtags());
        $this->assertSame(1, $post->targets()->count(), 'A channel no longer listed is removed.');
    }

    public function test_overrides_are_validated(): void
    {
        $instagram = $this->account(Platform::Instagram);
        $post = $this->makePost();

        $this->actingAs($this->owner)->put(route('posts.update', $post), [
            'link_url' => 'not a link',
            'targets' => [[
                'social_account_id' => $instagram->id,
                'format' => 'short',
                'link_url' => 'nope',
                'hashtags_override' => '{"a": 1}',
            ]],
        ])->assertSessionHasErrors(['link_url', 'targets.0.hashtags_override']);

        $this->actingAs($this->owner)->put(route('posts.update', $post), [
            'targets' => [['social_account_id' => $instagram->id, 'format' => 'short', 'link_url' => 'nope']],
        ])->assertSessionHasErrors(['targets.0.format', 'targets.0.link_url']);
    }

    public function test_the_checklist_counts_each_channels_published_caption_and_warns_per_channel(): void
    {
        $x = $this->account(Platform::X);
        $linkedIn = $this->account(Platform::LinkedIn);
        $instagram = $this->account(Platform::Instagram);
        $pinterest = $this->account(Platform::Pinterest);
        $post = $this->makePost(['first_comment' => 'More soon']);
        $post->assets()->attach($this->image());
        $post->targets()->create(['social_account_id' => $x->id, 'caption_override' => str_repeat('a', 290)]);
        $post->targets()->create(['social_account_id' => $linkedIn->id, 'hashtags_override' => ['a', 'b', 'c', 'd', 'e', 'f']]);
        $post->targets()->create(['social_account_id' => $instagram->id, 'caption_override' => 'Menu: https://alima.test']);
        $post->targets()->create(['social_account_id' => $pinterest->id]);

        $result = (new EvaluatePostChecklistAction)($post->refresh());
        $items = collect($result['items'])->keyBy('key');

        $this->assertFalse($items['caption_length']['passed']);
        $this->assertStringContainsString('X (@x): 299/280 characters.', $items['caption_length']['detail']);
        $this->assertFalse($result['passed']);

        $this->assertFalse($items['hashtags']['passed']);
        $this->assertTrue($items['hashtags']['soft']);
        $this->assertStringContainsString('LinkedIn (@linkedin): 6 hashtags (works best with up to 5).', $items['hashtags']['detail']);

        $this->assertFalse($items['links']['passed']);
        $this->assertStringContainsString('Instagram (@instagram): links in captions aren’t clickable', $items['links']['detail']);

        $this->assertFalse($items['first_comment']['passed']);
        $this->assertStringContainsString('Pinterest doesn’t support first comments', $items['first_comment']['detail']);

        $this->assertFalse($items['account_settings']['passed']);
        $this->assertStringContainsString('Pinterest (@pinterest): choose the board', $items['account_settings']['detail']);
    }

    public function test_the_editor_and_the_review_link_show_each_channels_published_caption(): void
    {
        $instagram = $this->account(Platform::Instagram);
        $post = $this->makePost(['status' => PostStatus::WaitingClient, 'hashtags' => []]);
        $post->targets()->create(['social_account_id' => $instagram->id, 'caption_override' => 'Just for Instagram', 'first_comment' => 'Hi']);

        $this->actingAs($this->owner)->get(route('posts.edit', $post))
            ->assertInertia(fn ($page) => $page
                ->where('post.targets.0.caption_override', 'Just for Instagram')
                ->where('post.targets.0.published_caption', 'Just for Instagram')
                ->where('post.targets.0.is_customized', true)
                ->where('post.targets.0.resolved_format', 'feed'));

        ['token' => $token] = (new CreateReviewTokenAction)($this->client, $post);

        $this->get(route('review.show', $token))
            ->assertInertia(fn ($page) => $page
                ->where('post.targets.0.published_caption', 'Just for Instagram')
                ->where('post.targets.0.first_comment', 'Hi')
                ->missing('post.targets.0.caption_override'));
    }
}
