<?php

namespace Tests\Unit\Support\AI;

use App\Enums\CampaignStatus;
use App\Models\BrandBrain;
use App\Models\Campaign;
use App\Models\Client;
use App\Support\AI\ClientContextBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientContextBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_includes_the_client_profile_and_full_brand_brain(): void
    {
        $client = Client::factory()->create([
            'name' => 'Acme Fitness',
            'industry' => 'Fitness',
            'website' => 'https://acme.test',
            'countries' => ['GB', 'IE'],
            'default_language' => 'en',
        ]);
        BrandBrain::factory()->for($client)->create([
            'voice' => ['tone' => 'Playful and bold', 'personality' => 'Coach', 'do_nots' => ['Never shame bodies']],
            'audience' => ['description' => 'Busy parents', 'demographics' => '30-45', 'pain_points' => ['No time to train']],
            'offer' => ['value_proposition' => '20-minute home workouts', 'key_products' => ['Acme App'], 'pricing_notes' => null],
            'hashtag_policy' => ['always_use' => ['#AcmeMoves'], 'never_use' => ['#nopainnogain'], 'rotation_notes' => null],
            'content_pillars' => ['Quick workouts', 'Member wins'],
        ]);

        $context = (new ClientContextBuilder)->build($client);

        $this->assertStringContainsString('- Name: Acme Fitness', $context);
        $this->assertStringContainsString('- Industry: Fitness', $context);
        $this->assertStringContainsString('- Website: https://acme.test', $context);
        $this->assertStringContainsString('- Markets/countries: GB, IE', $context);
        $this->assertStringContainsString('- Primary content language: en', $context);
        $this->assertStringContainsString('Playful and bold', $context);
        $this->assertStringContainsString('Never shame bodies', $context);
        $this->assertStringContainsString('No time to train', $context);
        $this->assertStringContainsString('20-minute home workouts', $context);
        $this->assertStringContainsString('#AcmeMoves', $context);
        $this->assertStringContainsString('Quick workouts, Member wins', $context);
    }

    public function test_it_only_lists_planning_and_active_campaigns_with_their_goals(): void
    {
        $client = Client::factory()->create();
        Campaign::factory()->for($client)->create(['name' => 'Spring Launch', 'status' => CampaignStatus::Active, 'goal' => 'Drive app signups']);
        Campaign::factory()->for($client)->create(['name' => 'Summer Teaser', 'status' => CampaignStatus::Planning]);
        Campaign::factory()->for($client)->create(['name' => 'Old Promo', 'status' => CampaignStatus::Completed]);

        $context = (new ClientContextBuilder)->build($client);

        $this->assertStringContainsString('Spring Launch (Active)', $context);
        $this->assertStringContainsString('goal: Drive app signups', $context);
        $this->assertStringContainsString('Summer Teaser (Planning)', $context);
        $this->assertStringNotContainsString('Old Promo', $context);
    }

    public function test_it_handles_a_new_client_with_no_brand_brain_or_campaigns(): void
    {
        $client = Client::factory()->create(['industry' => null, 'website' => null, 'countries' => []]);

        $context = (new ClientContextBuilder)->build($client);

        $this->assertStringContainsString('- Industry: Unknown', $context);
        $this->assertStringContainsString('No brand brain has been filled in', $context);
        $this->assertStringContainsString("# Current campaigns\n(none)", $context);
        $this->assertStringNotContainsString('Website:', $context);
    }

    public function test_it_scrubs_pii_typed_into_the_brand_brain(): void
    {
        $client = Client::factory()->create();
        BrandBrain::factory()->for($client)->create([
            'offer' => ['value_proposition' => 'Email founder@acme.test for deals', 'key_products' => [], 'pricing_notes' => null],
        ]);

        $context = (new ClientContextBuilder)->build($client);

        $this->assertStringNotContainsString('founder@acme.test', $context);
        $this->assertStringContainsString('[redacted-email]', $context);
    }

    public function test_the_fingerprint_changes_when_the_brand_brain_changes(): void
    {
        $client = Client::factory()->create();
        $brandBrain = BrandBrain::factory()->for($client)->create();
        $builder = new ClientContextBuilder;

        $before = $builder->fingerprint($client->fresh());
        $this->assertSame($before, $builder->fingerprint($client->fresh()));

        $brandBrain->update(['content_pillars' => ['A brand new pillar']]);

        $this->assertNotSame($before, $builder->fingerprint($client->fresh()));
    }
}
