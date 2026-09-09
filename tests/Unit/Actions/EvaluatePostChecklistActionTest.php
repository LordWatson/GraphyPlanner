<?php

namespace Tests\Unit\Actions;

use App\Actions\Posts\EvaluatePostChecklistAction;
use App\Enums\AssetSource;
use App\Enums\Platform;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Post;
use App\Models\SocialAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EvaluatePostChecklistActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_checklist_fails_when_caption_targets_and_media_are_missing(): void
    {
        $org = Organization::factory()->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $post = Post::factory()->for($client)->create(['org_id' => $org->id, 'master_caption' => null]);

        $result = (new EvaluatePostChecklistAction)($post);

        $this->assertFalse($result['passed']);
        $failing = collect($result['items'])->firstWhere('key', 'caption');
        $this->assertFalse($failing['passed']);
    }

    public function test_checklist_passes_without_media_when_every_target_platform_is_text_only_capable(): void
    {
        $org = Organization::factory()->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $post = Post::factory()->for($client)->create(['org_id' => $org->id]);
        $account = SocialAccount::factory()->for($client)->create([
            'org_id' => $org->id,
            'platform' => Platform::LinkedIn,
        ]);
        $post->targets()->create([
            'social_account_id' => $account->id,
            'scheduled_local_date' => now()->addDay()->toDateString(),
            'scheduled_local_time' => '09:00',
        ]);

        $result = (new EvaluatePostChecklistAction)($post->refresh());

        $this->assertTrue($result['passed']);
    }

    public function test_checklist_requires_media_when_a_target_platform_is_not_text_only_capable(): void
    {
        $org = Organization::factory()->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $post = Post::factory()->for($client)->create(['org_id' => $org->id]);
        $account = SocialAccount::factory()->for($client)->create([
            'org_id' => $org->id,
            'platform' => Platform::Instagram,
        ]);
        $post->targets()->create([
            'social_account_id' => $account->id,
            'scheduled_local_date' => now()->addDay()->toDateString(),
            'scheduled_local_time' => '09:00',
        ]);

        $withoutMedia = (new EvaluatePostChecklistAction)($post->refresh());
        $this->assertFalse($withoutMedia['passed']);

        $asset = Asset::factory()->for($client)->create([
            'org_id' => $org->id,
            'source' => AssetSource::Url,
            'url' => 'https://example.com/image.png',
        ]);
        $post->assets()->attach($asset);

        $withMedia = (new EvaluatePostChecklistAction)($post->refresh());
        $this->assertTrue($withMedia['passed']);
    }
}
