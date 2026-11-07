<?php

namespace Tests\Feature\Publishing;

use App\Enums\ConnectionStatus;
use App\Enums\OrganizationType;
use App\Enums\Platform;
use App\Models\Organization;
use App\Models\SocialAccount;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The 2027_01_16 migration's backfill. Accounts linked before the vendor profile became per client
 * must keep pointing at the profile their vendor link actually lives on — otherwise this bugfix
 * would silently publish them into an empty profile.
 */
class UploadPostProfileBackfillTest extends TestCase
{
    use RefreshDatabase;

    private function backfill(): void
    {
        // Re-run the migration over the fixtures below: `RefreshDatabase` has already applied it
        // (against no rows), so start from the column it added not existing.
        Schema::table('social_accounts', fn (Blueprint $table) => $table->dropColumn('upload_post_profile'));

        (require database_path('migrations/2027_01_16_100100_add_upload_post_profile_to_social_accounts.php'))->up();
    }

    public function test_a_connected_byo_account_keeps_its_per_account_profile(): void
    {
        $org = Organization::factory()->create(['type' => OrganizationType::Agency]);
        $account = SocialAccount::factory()->for($org, 'organization')->connected()->create([
            'platform' => Platform::Instagram,
            'external_account_id' => 'ext-acct-1',
        ]);

        $this->backfill();

        $this->assertSame('ext-acct-1', $account->fresh()->upload_post_profile);
    }

    public function test_a_connected_managed_account_keeps_the_orgs_profile(): void
    {
        $org = Organization::factory()->create(['type' => OrganizationType::Business]);
        $org->forceFill(['upload_post_profile' => 'alima-org-legacy'])->save();
        $account = SocialAccount::factory()->for($org, 'organization')->connected()->create([
            'platform' => Platform::Instagram,
            'external_account_id' => 'ext-acct-should-not-be-used',
        ]);

        $this->backfill();

        $this->assertSame('alima-org-legacy', $account->fresh()->upload_post_profile);
    }

    public function test_an_account_that_was_never_linked_is_left_for_the_new_grouping(): void
    {
        $org = Organization::factory()->create(['type' => OrganizationType::Agency]);
        $account = SocialAccount::factory()->for($org, 'organization')->create([
            'platform' => Platform::Instagram,
            'connection_status' => ConnectionStatus::NotConnected,
            'external_account_id' => 'ext-acct-2',
        ]);

        $this->backfill();

        $this->assertNull($account->fresh()->upload_post_profile);
    }
}
