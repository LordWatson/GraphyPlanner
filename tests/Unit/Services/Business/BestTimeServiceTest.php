<?php

namespace Tests\Unit\Services\Business;

use App\Enums\ConnectionStatus;
use App\Enums\Platform;
use App\Models\Client;
use App\Models\Organization;
use App\Models\SocialAccount;
use App\Services\Analytics\BestTimeService as EngagementBestTimeService;
use App\Services\Business\BestTimeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Step B-8.3 — the business "best times" service: learned slots where the brand has enough history,
 * a sensible platform default everywhere else, each in the account's timezone.
 */
class BestTimeServiceTest extends TestCase
{
    use RefreshDatabase;

    private function brandWithAccounts(): array
    {
        $org = Organization::factory()->business()->create();
        $brand = Client::factory()->for($org, 'organization')->primary()->create();

        $instagram = SocialAccount::factory()->for($brand)->create([
            'org_id' => $org->id,
            'platform' => Platform::Instagram,
            'handle' => 'ig',
            'timezone' => 'Europe/London',
            'connection_status' => ConnectionStatus::Connected,
        ]);

        $tiktok = SocialAccount::factory()->for($brand)->create([
            'org_id' => $org->id,
            'platform' => Platform::TikTok,
            'handle' => 'tt',
            'timezone' => 'Europe/London',
            'connection_status' => ConnectionStatus::Connected,
        ]);

        return [$brand, $instagram, $tiktok];
    }

    public function test_it_uses_learned_slots_where_the_data_is_ready_and_defaults_elsewhere(): void
    {
        [$brand, $instagram, $tiktok] = $this->brandWithAccounts();

        $engagement = Mockery::mock(EngagementBestTimeService::class);
        $engagement->shouldReceive('forAccounts')
            ->once()
            ->andReturnUsing(fn (array $ids) => [
                $instagram->id => [
                    'status' => 'ready',
                    'sample' => 9,
                    'needed' => 6,
                    'ranked_by' => 'rate',
                    'slots' => [
                        ['weekday' => 2, 'hour' => 8, 'block_hours' => 2, 'label' => 'Tue 08:00–10:00', 'posts' => 4, 'score' => 5.1],
                        ['weekday' => 4, 'hour' => 18, 'block_hours' => 2, 'label' => 'Thu 18:00–20:00', 'posts' => 3, 'score' => 3.2],
                    ],
                ],
                $tiktok->id => ['status' => 'learning', 'sample' => 1, 'needed' => 6, 'ranked_by' => null, 'slots' => []],
            ]);

        $service = new BestTimeService($engagement);
        $result = $service->forBrand($brand->fresh());

        $byAccount = collect($result)->keyBy('social_account_id');

        $ig = $byAccount[$instagram->id];
        $this->assertSame('data', $ig['source']);
        $this->assertSame(['weekday' => 2, 'hour' => 8, 'label' => 'Tue 08:00'], $ig['slot']);
        $this->assertCount(1, $ig['alternatives']);
        $this->assertSame(9, $ig['sample']);

        $tt = $byAccount[$tiktok->id];
        $this->assertSame('default', $tt['source']);
        // TikTok's configured default is Fri 19:00.
        $this->assertSame(['weekday' => 5, 'hour' => 19, 'label' => 'Fri 19:00'], $tt['slot']);
        $this->assertSame([], $tt['alternatives']);
    }

    public function test_default_slot_falls_back_to_the_general_default_for_an_unmapped_platform(): void
    {
        config()->set('analytics.best_time.defaults', ['default' => [3, 10]]);

        $service = new BestTimeService(Mockery::mock(EngagementBestTimeService::class));

        $this->assertSame(
            ['weekday' => 3, 'hour' => 10, 'label' => 'Wed 10:00'],
            $service->defaultSlotFor(Platform::Instagram, 'Europe/London'),
        );
    }

    public function test_it_returns_nothing_when_the_brand_has_no_connected_accounts(): void
    {
        $org = Organization::factory()->business()->create();
        $brand = Client::factory()->for($org, 'organization')->primary()->create();

        $engagement = Mockery::mock(EngagementBestTimeService::class);
        $engagement->shouldNotReceive('forAccounts');

        $this->assertSame([], (new BestTimeService($engagement))->forBrand($brand));
    }

    public function test_suggest_for_account_handles_an_invalid_timezone(): void
    {
        [$brand, $instagram] = $this->brandWithAccounts();
        $instagram->forceFill(['timezone' => 'Not/AZone'])->save();

        $engagement = Mockery::mock(EngagementBestTimeService::class);
        $engagement->shouldReceive('forAccounts')->andReturn([
            $instagram->id => ['status' => 'learning', 'sample' => 0, 'needed' => 6, 'ranked_by' => null, 'slots' => []],
        ]);

        $suggestion = (new BestTimeService($engagement))->suggestForAccount($instagram->fresh());

        // Instagram's configured default is Wed 11:00; source is the fallback.
        $this->assertSame('default', $suggestion['source']);
        $this->assertSame(3, $suggestion['weekday']);
        $this->assertSame(11, $suggestion['hour']);
    }
}
