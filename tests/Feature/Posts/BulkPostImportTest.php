<?php

namespace Tests\Feature\Posts;

use App\Actions\Posts\CreatePostsInBulkAction;
use App\Actions\Posts\ParseBulkPostImportAction;
use App\Enums\CampaignStatus;
use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\Role;
use App\Models\Campaign;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Step M-5.8 (Phase 10) — bulk CSV import: parsing, all-or-nothing batch creation, and the
 * upload/preview/store endpoints.
 */
class BulkPostImportTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Client $client;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-17 12:00:00');

        $this->org = Organization::factory()->create();
        $this->client = Client::factory()->for($this->org, 'organization')->create();
        $this->owner = User::factory()->for($this->org, 'organization')->role(Role::Owner)->create();
    }

    private function account(string $handle, string $timezone = 'UTC', Platform $platform = Platform::Instagram): SocialAccount
    {
        return SocialAccount::factory()->for($this->client)->create([
            'org_id' => $this->org->id,
            'handle' => $handle,
            'timezone' => $timezone,
            'platform' => $platform,
        ]);
    }

    public function test_the_parser_reports_a_bad_handle_row_and_keeps_the_valid_one(): void
    {
        $this->account('@harbour');

        $csv = "caption,hashtags,accounts,date,time\n"
            ."Hello,coffee launch,@harbour,2026-11-01,09:00\n"
            ."Oops,,@nope,2026-11-02,10:00\n";

        $result = (new ParseBulkPostImportAction)($this->client, $csv);

        $this->assertCount(1, $result->rows);
        $this->assertCount(1, $result->errors);
        $this->assertSame(3, $result->errors[0]['row']);
        $this->assertStringContainsString('Unknown account', $result->errors[0]['messages'][0]);

        $row = $result->rows[0];
        $this->assertSame('Hello', $row->caption);
        $this->assertSame(['coffee', 'launch'], $row->hashtags);
        $this->assertSame('2026-11-01', $row->scheduledLocalDate);
    }

    public function test_bad_dates_and_times_are_reported(): void
    {
        $this->account('@harbour');

        $csv = "caption,accounts,date,time\nHi,@harbour,2026-13-99,25:00\n";
        $result = (new ParseBulkPostImportAction)($this->client, $csv);

        $this->assertCount(0, $result->rows);
        $this->assertCount(1, $result->errors);
        $this->assertCount(2, $result->errors[0]['messages']);
    }

    public function test_creating_in_bulk_schedules_each_account_in_its_own_timezone(): void
    {
        $london = $this->account('@london', 'Europe/London');
        $dubai = $this->account('@dubai', 'Asia/Dubai');

        $csv = "caption,accounts,date,time\nSame moment,@london;@dubai,2026-11-01,09:00\n";
        $result = (new ParseBulkPostImportAction)($this->client, $csv);
        $this->assertCount(1, $result->rows);

        $created = (new CreatePostsInBulkAction)($this->client, new Collection($result->rows), $this->owner);

        $this->assertSame(1, $created);
        $post = Post::query()->firstOrFail();
        $this->assertSame(PostStatus::Draft, $post->status);
        $this->assertSame('Same moment', $post->master_caption);

        $londonTarget = $post->targets->firstWhere('social_account_id', $london->id);
        $dubaiTarget = $post->targets->firstWhere('social_account_id', $dubai->id);
        // 09:00 London (GMT on 1 Nov) = 09:00 UTC; 09:00 Dubai = 05:00 UTC.
        $this->assertSame('2026-11-01T09:00:00+00:00', $londonTarget?->scheduled_at_utc?->toIso8601String());
        $this->assertSame('2026-11-01T05:00:00+00:00', $dubaiTarget?->scheduled_at_utc?->toIso8601String());
    }

    public function test_a_row_can_carry_a_campaign_code_and_format(): void
    {
        $this->account('@harbour');
        $campaign = Campaign::factory()->for($this->client)->create([
            'org_id' => $this->org->id,
            'code' => 'XMAS',
            'status' => CampaignStatus::Active,
        ]);

        $csv = "caption,accounts,date,time,format,campaign\nFestive,@harbour,2026-12-01,09:00,reel,XMAS\n";
        $result = (new ParseBulkPostImportAction)($this->client, $csv);

        $this->assertCount(1, $result->rows);
        $this->assertSame($campaign->id, $result->rows[0]->campaignId);

        (new CreatePostsInBulkAction)($this->client, new Collection($result->rows), $this->owner);
        $post = Post::query()->firstOrFail();
        $this->assertSame($campaign->id, $post->campaign_id);
        $this->assertSame('reel', $post->targets->first()?->format);
    }

    public function test_the_store_endpoint_imports_valid_rows_as_drafts(): void
    {
        $this->account('@harbour');

        $csv = "caption,accounts,date,time\nFirst,@harbour,2026-11-01,09:00\nSecond,@harbour,2026-11-02,10:00\n";
        $file = UploadedFile::fake()->createWithContent('import.csv', $csv);

        $this->actingAs($this->owner)
            ->post(route('clients.posts.bulk-store', $this->client), ['file' => $file])
            ->assertRedirect(route('clients.posts.index', $this->client));

        $this->assertSame(2, Post::query()->where('client_id', $this->client->id)->count());
        $this->assertTrue(Post::query()->where('master_caption', 'First')->where('status', PostStatus::Draft)->exists());
    }

    public function test_the_store_endpoint_imports_nothing_when_a_row_is_invalid(): void
    {
        $this->account('@harbour');

        $csv = "caption,accounts,date,time\nGood,@harbour,2026-11-01,09:00\nBad,@nope,2026-11-02,10:00\n";
        $file = UploadedFile::fake()->createWithContent('import.csv', $csv);

        $this->actingAs($this->owner)
            ->post(route('clients.posts.bulk-store', $this->client), ['file' => $file]);

        $this->assertSame(0, Post::query()->count());
    }

    public function test_the_preview_endpoint_returns_rows_and_errors(): void
    {
        $this->account('@harbour');

        $csv = "caption,accounts\nGood,@harbour\nBad,@nope\n";
        $file = UploadedFile::fake()->createWithContent('import.csv', $csv);

        $this->actingAs($this->owner)
            ->postJson(route('clients.posts.bulk-import.preview', $this->client), ['file' => $file])
            ->assertOk()
            ->assertJson(['valid_count' => 1, 'error_count' => 1]);
    }

    public function test_a_viewer_cannot_import(): void
    {
        $viewer = User::factory()->for($this->org, 'organization')->role(Role::Viewer)->create();
        $csv = "caption,accounts\nHi,@harbour\n";
        $file = UploadedFile::fake()->createWithContent('import.csv', $csv);

        $this->actingAs($viewer)
            ->post(route('clients.posts.bulk-store', $this->client), ['file' => $file])
            ->assertForbidden();
    }
}
