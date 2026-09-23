<?php

namespace Tests\Unit\Services\Publishing;

use App\Enums\AssetType;
use App\Enums\Platform;
use App\Enums\PlatformRolloutStage;
use App\Models\Asset;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Services\Publishing\PlatformRolloutGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class PlatformRolloutGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_resolves_the_instagram_feed_stage_for_an_image_or_asset_less_post(): void
    {
        $account = SocialAccount::factory()->create(['platform' => Platform::Instagram]);
        $post = Post::factory()->create(['client_id' => $account->client_id]);
        $post->load('assets');

        $this->assertSame(PlatformRolloutStage::InstagramFeed, (new PlatformRolloutGate)->stageFor($post, $account));
    }

    public function test_it_resolves_the_instagram_reels_stage_for_a_video_post(): void
    {
        $account = SocialAccount::factory()->create(['platform' => Platform::Instagram]);
        $post = Post::factory()->create(['client_id' => $account->client_id]);
        $asset = Asset::factory()->create(['client_id' => $post->client_id, 'type' => AssetType::Video]);
        $post->assets()->attach($asset);
        $post->load('assets');

        $this->assertSame(PlatformRolloutStage::InstagramReels, (new PlatformRolloutGate)->stageFor($post, $account));
    }

    public function test_it_resolves_tiktok_facebook_and_linkedin_stages_directly_from_the_platform(): void
    {
        $post = Post::factory()->create();
        $post->load('assets');

        foreach ([
            [Platform::TikTok, PlatformRolloutStage::TikTok],
            [Platform::Facebook, PlatformRolloutStage::Facebook],
            [Platform::LinkedIn, PlatformRolloutStage::LinkedIn],
        ] as [$platform, $stage]) {
            $account = SocialAccount::factory()->create(['platform' => $platform, 'client_id' => $post->client_id]);

            $this->assertSame($stage, (new PlatformRolloutGate)->stageFor($post, $account));
        }
    }

    public function test_is_enabled_reflects_the_stage_config_flag(): void
    {
        Config::set('publishing.rollout.'.PlatformRolloutStage::TikTok->value, false);

        $account = SocialAccount::factory()->create(['platform' => Platform::TikTok]);
        $post = Post::factory()->create(['client_id' => $account->client_id]);
        $post->load('assets');

        $gate = new PlatformRolloutGate;

        $this->assertFalse($gate->isEnabled($post, $account));
        $this->assertFalse($gate->stageEnabled(PlatformRolloutStage::TikTok));

        Config::set('publishing.rollout.'.PlatformRolloutStage::TikTok->value, true);

        $this->assertTrue($gate->isEnabled($post, $account));
    }

    public function test_a_stage_missing_from_config_defaults_to_disabled(): void
    {
        Config::set('publishing.rollout', []);

        $this->assertFalse((new PlatformRolloutGate)->stageEnabled(PlatformRolloutStage::LinkedIn));
    }
}
