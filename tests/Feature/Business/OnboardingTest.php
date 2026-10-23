<?php

namespace Tests\Feature\Business;

use App\Actions\Workspace\CreatePrimaryBrandAction;
use App\Contracts\AiProvider;
use App\Enums\ConnectionStatus;
use App\Enums\OrganizationType;
use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\Role;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Workspace\OnboardingProgress;
use App\Support\AI\AiBrandProfileDraft;
use App\Support\AI\AiCaptionSuggestions;
use App\Support\AI\AiPostIdeas;
use App\Support\AI\AiTrends;
use App\Support\AI\BrandProfileInput;
use App\Support\AI\Images\AiImageBrief;
use App\Support\AI\Images\ImageBriefInput;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OnboardingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Organization, 1: Client, 2: User}
     */
    private function businessWorkspace(): array
    {
        $organization = Organization::factory()->business()->create();
        $brand = (new CreatePrimaryBrandAction)($organization);
        $owner = User::factory()->create([
            'org_id' => $organization->id,
            'role' => Role::Owner,
            'email_verified_at' => now(),
        ]);

        return [$organization, $brand, $owner];
    }

    // ---- OnboardingProgress (checklist from data) ---------------------------------------------

    public function test_every_required_item_starts_incomplete_for_a_fresh_workspace(): void
    {
        [$organization] = $this->businessWorkspace();

        $progress = app(OnboardingProgress::class)->for($organization->refresh());

        $this->assertFalse($progress['requiredComplete']);
        $this->assertNull($progress['completedAt']);
        foreach ($progress['items'] as $item) {
            $this->assertFalse($item['done'], "{$item['key']} should start incomplete");
        }
    }

    public function test_the_brand_item_flips_when_what_you_do_is_filled(): void
    {
        [$organization, $brand] = $this->businessWorkspace();
        $brand->update(['industry' => 'A neighbourhood bakery.']);

        $this->assertTrue($this->item($organization, OnboardingProgress::BRAND));
    }

    public function test_the_accounts_item_flips_when_an_account_is_connected(): void
    {
        [$organization, $brand] = $this->businessWorkspace();

        SocialAccount::factory()->create([
            'org_id' => $organization->id,
            'client_id' => $brand->id,
            'platform' => Platform::Instagram,
            'connection_status' => ConnectionStatus::NotConnected,
        ]);
        $this->assertFalse($this->item($organization, OnboardingProgress::ACCOUNTS));

        SocialAccount::factory()->create([
            'org_id' => $organization->id,
            'client_id' => $brand->id,
            'platform' => Platform::Facebook,
            'connection_status' => ConnectionStatus::Connected,
        ]);
        $this->assertTrue($this->item($organization, OnboardingProgress::ACCOUNTS));
    }

    public function test_the_first_post_item_flips_on_a_scheduled_post(): void
    {
        [$organization, $brand] = $this->businessWorkspace();

        Post::factory()->create([
            'org_id' => $organization->id,
            'client_id' => $brand->id,
            'status' => PostStatus::Draft,
        ]);
        $this->assertFalse($this->item($organization, OnboardingProgress::FIRST_POST));

        Post::factory()->create([
            'org_id' => $organization->id,
            'client_id' => $brand->id,
            'status' => PostStatus::Scheduled,
        ]);
        $this->assertTrue($this->item($organization, OnboardingProgress::FIRST_POST));
    }

    public function test_the_team_item_is_optional_and_flips_with_a_second_user(): void
    {
        [$organization, , $owner] = $this->businessWorkspace();

        $teamItem = collect(app(OnboardingProgress::class)->for($organization)['items'])
            ->firstWhere('key', OnboardingProgress::TEAM);
        $this->assertTrue($teamItem['optional']);
        $this->assertFalse($teamItem['done']);

        User::factory()->create(['org_id' => $organization->id, 'role' => Role::Designer]);
        $this->assertTrue($this->item($organization, OnboardingProgress::TEAM));
    }

    private function item(Organization $organization, string $key): bool
    {
        return (bool) collect(app(OnboardingProgress::class)->for($organization)['items'])
            ->firstWhere('key', $key)['done'];
    }

    // ---- The wizard routes --------------------------------------------------------------------

    public function test_a_business_owner_sees_the_wizard_and_an_agency_cannot(): void
    {
        [, , $owner] = $this->businessWorkspace();

        $this->actingAs($owner)->get(route('business.onboarding'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('business/onboarding')->has('progress'));

        $agencyOwner = User::factory()->create([
            'org_id' => Organization::factory()->create(['type' => OrganizationType::Agency])->id,
            'role' => Role::Owner,
            'email_verified_at' => now(),
        ]);
        $this->actingAs($agencyOwner)->get(route('business.onboarding'))->assertNotFound();
    }

    public function test_saving_the_brand_basics_updates_the_primary_brand(): void
    {
        [, $brand, $owner] = $this->businessWorkspace();

        $this->actingAs($owner)
            ->put(route('business.onboarding.brand'), [
                'name' => 'Sourdough & Co.',
                'one_liner' => 'A neighbourhood bakery.',
                'location' => 'Bristol, UK',
                'website' => 'sourdough.test',
            ])
            ->assertRedirect();

        $brand->refresh();
        $this->assertSame('Sourdough & Co.', $brand->name);
        $this->assertSame('A neighbourhood bakery.', $brand->industry);
        $this->assertSame('Bristol, UK', $brand->location);
    }

    public function test_saving_the_voice_writes_to_the_brand_brain(): void
    {
        [, $brand, $owner] = $this->businessWorkspace();

        $this->actingAs($owner)
            ->put(route('business.onboarding.voice'), [
                'voice' => ['tone' => 'Warm', 'personality' => 'Friendly', 'do_nots' => ['Hard selling']],
                'content_pillars' => ['Behind the counter'],
            ])
            ->assertRedirect();

        $brain = $brand->brandBrain()->first();
        $this->assertSame('Warm', $brain->voice['tone']);
        $this->assertSame(['Behind the counter'], $brain->content_pillars);
    }

    public function test_the_ai_quick_start_returns_a_draft_without_saving(): void
    {
        [, $brand, $owner] = $this->businessWorkspace();

        $this->app->instance(AiProvider::class, new class implements AiProvider
        {
            public function suggestCaptions(Post $post): AiCaptionSuggestions
            {
                return new AiCaptionSuggestions;
            }

            public function recommendPosts(Client $client): AiPostIdeas
            {
                return new AiPostIdeas;
            }

            public function discoverTrends(Client $client, array $platforms): AiTrends
            {
                return new AiTrends;
            }

            public function draftImageBrief(Client $client, ImageBriefInput $input): AiImageBrief
            {
                return new AiImageBrief(subject: $input->title);
            }

            public function draftBrandProfile(BrandProfileInput $input): AiBrandProfileDraft
            {
                return AiBrandProfileDraft::fromArray([
                    'voice' => ['tone' => 'Warm', 'personality' => null, 'do_nots' => []],
                ], succeeded: true, provider: 'deepseek', model: 'deepseek-flash');
            }
        });

        $this->actingAs($owner)
            ->postJson(route('business.onboarding.draft'), ['name' => 'Sourdough & Co.'])
            ->assertOk()
            ->assertJsonPath('draft.succeeded', true)
            ->assertJsonPath('draft.voice.tone', 'Warm');

        // Draft only — nothing persisted.
        $this->assertNull($brand->brandBrain()->first()->voice);
    }

    public function test_skipping_stamps_completion_and_redirects_to_the_dashboard(): void
    {
        [$organization, , $owner] = $this->businessWorkspace();

        $this->actingAs($owner)
            ->post(route('business.onboarding.skip'))
            ->assertRedirect(route('dashboard'));

        $this->assertNotNull($organization->refresh()->onboarding_completed_at);
    }

    public function test_completing_stamps_completion(): void
    {
        [$organization, , $owner] = $this->businessWorkspace();

        $this->actingAs($owner)->post(route('business.onboarding.complete'))->assertRedirect();

        $this->assertNotNull($organization->refresh()->onboarding_completed_at);
    }

    public function test_a_business_owner_needing_onboarding_lands_there_after_login(): void
    {
        [$organization, , $owner] = $this->businessWorkspace();
        $owner->forceFill(['password' => bcrypt('password')])->save();

        $this->post('/login', ['email' => $owner->email, 'password' => 'password'])
            ->assertRedirect('/welcome-setup');

        // Once onboarding is stamped, login falls through to the business "Today" home (B-4.2).
        auth()->logout();
        $organization->forceFill(['onboarding_completed_at' => now()])->save();

        $this->post('/login', ['email' => $owner->email, 'password' => 'password'])
            ->assertRedirect('/today');
    }
}
