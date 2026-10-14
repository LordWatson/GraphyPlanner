<?php

namespace Tests\Feature;

use App\Enums\ClientStatus;
use App\Models\Client;
use App\Models\DailyBrief;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class GenerateDailyBriefsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_generates_a_brief_for_every_active_client_and_is_idempotent(): void
    {
        Mail::fake();

        $org = Organization::factory()->create();
        User::factory()->for($org, 'organization')->create();
        $client = Client::factory()->for($org, 'organization')->create(['status' => ClientStatus::Active]);
        Client::factory()->for($org, 'organization')->create(['status' => ClientStatus::Archived]);

        $this->artisan('ai:generate-daily-briefs')->assertSuccessful();

        $this->assertDatabaseHas('daily_briefs', ['client_id' => $client->id]);
        $this->assertSame(1, DailyBrief::query()->count());

        // Re-running the same day never duplicates a brief for the same client.
        $this->artisan('ai:generate-daily-briefs')->assertSuccessful();

        $this->assertSame(1, DailyBrief::query()->count());
    }
}
