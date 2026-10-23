<?php

namespace Tests\Unit\Services\AI;

use App\Actions\AI\RecordAiFeedbackAction;
use App\Enums\AiRating;
use App\Models\AiPostIdea;
use App\Models\Client;
use App\Models\Organization;
use App\Models\User;
use App\Services\AI\AiPerformanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Step M-7.1 (dev plan Step 2.9) — acceptance-rate aggregation over `ai_feedback`.
 */
class AiPerformanceServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_aggregates_acceptance_rate_and_dismissal_reasons(): void
    {
        $org = Organization::factory()->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $record = new RecordAiFeedbackAction;

        $ideas = collect(range(1, 4))->map(fn (int $i) => AiPostIdea::query()->create([
            'org_id' => $org->id, 'client_id' => $client->id, 'title' => "Idea {$i}", 'description' => 'd',
        ]));

        $users = User::factory()->count(4)->for($org, 'organization')->create();

        // 2 positive (up + applied), 2 negative with a shared reason.
        $record($ideas[0], $users[0], AiRating::Up);
        $record($ideas[1], $users[1], AiRating::Applied);
        $record($ideas[2], $users[2], AiRating::Down, 'Off brand');
        $record($ideas[3], $users[3], AiRating::Down, 'off brand');

        $summary = (new AiPerformanceService)->forOrg($org);

        $this->assertSame(4, $summary['total']);
        $this->assertSame(2, $summary['positive']);
        $this->assertSame(2, $summary['negative']);
        $this->assertSame(1, $summary['applied']);
        $this->assertSame(0.5, $summary['acceptance_rate']);
        $this->assertSame('Off brand', $summary['top_dismissal_reasons'][0]['reason']);
        $this->assertSame(2, $summary['top_dismissal_reasons'][0]['count']);
    }

    public function test_acceptance_rate_is_null_without_any_feedback(): void
    {
        $org = Organization::factory()->create();

        $summary = (new AiPerformanceService)->forOrg($org);

        $this->assertSame(0, $summary['total']);
        $this->assertNull($summary['acceptance_rate']);
    }

    public function test_it_is_scoped_to_the_org(): void
    {
        $org = Organization::factory()->create();
        $other = Organization::factory()->create();
        $client = Client::factory()->for($other, 'organization')->create();
        $idea = AiPostIdea::query()->create(['org_id' => $other->id, 'client_id' => $client->id, 'title' => 'X', 'description' => 'd']);

        (new RecordAiFeedbackAction)($idea, User::factory()->for($other, 'organization')->create(), AiRating::Up);

        $this->assertSame(0, (new AiPerformanceService)->forOrg($org)['total']);
    }
}
