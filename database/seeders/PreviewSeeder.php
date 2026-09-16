<?php

namespace Database\Seeders;

use App\Enums\ApprovalMode;
use App\Enums\BillingCycle;
use App\Enums\CampaignStatus;
use App\Enums\ClientStatus;
use App\Enums\ConnectionStatus;
use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\Role;
use App\Models\Campaign;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Step 0.14 — Preview mode. Seeds one frozen, self-contained "Preview Studio" organization
 * that the `/preview/*` routes are scoped to, so demo visitors always see the same fixture
 * data and can never affect (or even see) real client data. Fully idempotent: safe to re-run
 * on every deploy via `updateOrCreate`, so the fixture never drifts or duplicates.
 */
class PreviewSeeder extends Seeder
{
    public function run(): void
    {
        $organization = Organization::updateOrCreate(
            ['slug' => 'preview-studio'],
            [
                'name' => 'Preview Studio',
                'default_timezone' => 'UTC',
            ]
        );

        $owner = User::updateOrCreate(
            ['email' => 'preview@graphy.test'],
            [
                'name' => 'Percy Preview',
                'password' => Hash::make(str()->random(40)),
                'email_verified_at' => now(),
                'org_id' => $organization->id,
                'role' => Role::Owner,
            ]
        );

        $client = Client::updateOrCreate(
            ['org_id' => $organization->id, 'name' => 'Fixture & Co'],
            [
                'legal_name' => 'Fixture & Co Ltd',
                'website' => 'https://fixtureandco.test',
                'industry' => 'Hospitality',
                'countries' => ['US'],
                'status' => ClientStatus::Active,
                'owner_user_id' => $owner->id,
                'start_date' => now()->subMonths(3)->toDateString(),
                'retainer_amount' => 1800,
                'billing_cycle' => BillingCycle::Monthly,
                'tags' => ['demo', 'preview'],
                'default_language' => 'en',
                'notes_internal' => 'Frozen fixture data for the preview mode demo — never edit.',
                'approval_email' => 'approvals@fixtureandco.test',
            ]
        );

        $campaign = Campaign::updateOrCreate(
            ['org_id' => $organization->id, 'client_id' => $client->id, 'name' => 'Autumn Launch'],
            [
                'status' => CampaignStatus::Active,
                'start_date' => now()->subWeek()->toDateString(),
                'end_date' => now()->addMonth()->toDateString(),
                'goal' => 'Demo campaign used by preview mode.',
                'notes' => null,
            ]
        );

        $socialAccount = SocialAccount::updateOrCreate(
            ['org_id' => $organization->id, 'client_id' => $client->id, 'handle' => '@fixtureandco'],
            [
                'platform' => Platform::Instagram,
                'display_name' => 'Fixture & Co',
                'timezone' => 'America/New_York',
                'language' => 'en',
                'country' => 'US',
                'default_location' => null,
                'posting_windows' => null,
                'persona_override' => null,
                'connection_status' => ConnectionStatus::Connected,
            ]
        );

        $post = Post::updateOrCreate(
            ['org_id' => $organization->id, 'client_id' => $client->id, 'master_caption' => 'Sample preview post — frozen fixture data.'],
            [
                'campaign_id' => $campaign->id,
                'created_by' => $owner->id,
                'status' => PostStatus::WaitingClient,
                'approval_mode' => ApprovalMode::ClientRequired,
                'hashtags' => ['#preview', '#demo'],
                'music' => null,
                'location' => null,
                'checklist_snapshot' => null,
            ]
        );

        PostTarget::updateOrCreate(
            ['post_id' => $post->id, 'social_account_id' => $socialAccount->id],
            [
                'scheduled_local_date' => now()->addDays(3)->toDateString(),
                'scheduled_local_time' => '09:00:00',
                'scheduled_at_utc' => now()->addDays(3)->setTime(13, 0),
            ]
        );
    }
}
