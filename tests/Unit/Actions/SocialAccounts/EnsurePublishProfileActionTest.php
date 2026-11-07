<?php

namespace Tests\Unit\Actions\SocialAccounts;

use App\Actions\SocialAccounts\EnsurePublishProfileAction;
use App\Enums\Platform;
use App\Models\Client;
use App\Models\SocialAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Upload-Post charges per *profile*, and a profile holds one connected account per platform. One
 * client's accounts must therefore share one profile — until a platform slot collides.
 */
class EnsurePublishProfileActionTest extends TestCase
{
    use RefreshDatabase;

    private function resolve(SocialAccount $account, bool $relinking = false): string
    {
        return (new EnsurePublishProfileAction)($account, $relinking);
    }

    private function account(Client $client, Platform $platform, ?string $profile = null): SocialAccount
    {
        $account = SocialAccount::factory()->create([
            'client_id' => $client->id,
            'org_id' => $client->org_id,
            'platform' => $platform,
        ]);

        if ($profile !== null) {
            $account->forceFill(['upload_post_profile' => $profile])->save();
        }

        return $account;
    }

    public function test_every_network_of_one_client_shares_a_single_profile(): void
    {
        $client = Client::factory()->create();

        $profiles = collect([Platform::Instagram, Platform::TikTok, Platform::LinkedIn, Platform::YouTube])
            ->map(fn (Platform $platform) => $this->resolve($this->account($client, $platform)))
            ->unique();

        $this->assertCount(1, $profiles, 'four networks for one client must cost one vendor profile');
        $this->assertStringStartsWith("alima-client-{$client->id}-", (string) $profiles->first());
    }

    public function test_the_profile_is_persisted_and_never_changes_once_assigned(): void
    {
        $account = $this->account(Client::factory()->create(), Platform::Instagram);

        $first = $this->resolve($account);

        $this->assertSame($first, $account->fresh()->upload_post_profile);
        $this->assertSame($first, $this->resolve($account->fresh()));
    }

    public function test_two_accounts_on_the_same_platform_need_two_profiles(): void
    {
        // The vendor addresses a published post as profile + platform, so it cannot hold two
        // Instagram accounts in one profile — this client genuinely costs two.
        $client = Client::factory()->create();

        $first = $this->resolve($this->account($client, Platform::Instagram));
        $second = $this->resolve($this->account($client, Platform::Instagram));

        $this->assertNotSame($first, $second);
    }

    public function test_a_third_network_joins_whichever_profile_has_the_slot_free(): void
    {
        $client = Client::factory()->create();

        $firstInstagram = $this->resolve($this->account($client, Platform::Instagram));
        $secondInstagram = $this->resolve($this->account($client, Platform::Instagram));
        $tiktok = $this->resolve($this->account($client, Platform::TikTok));

        $this->assertContains($tiktok, [$firstInstagram, $secondInstagram]);
    }

    public function test_each_client_gets_its_own_profile(): void
    {
        $this->assertNotSame(
            $this->resolve($this->account(Client::factory()->create(), Platform::Instagram)),
            $this->resolve($this->account(Client::factory()->create(), Platform::Instagram)),
        );
    }

    public function test_relinking_folds_a_legacy_profile_into_its_siblings(): void
    {
        // The state the bug left behind: one client, two networks, two paid-for profiles.
        $client = Client::factory()->create();
        $this->account($client, Platform::Instagram, 'legacy-instagram');
        $tiktok = $this->account($client, Platform::TikTok, 'legacy-tiktok');

        $this->assertSame('legacy-instagram', $this->resolve($tiktok, relinking: true));
        $this->assertSame('legacy-instagram', $tiktok->fresh()->upload_post_profile);
    }

    public function test_relinking_keeps_the_profile_when_no_sibling_has_the_slot_free(): void
    {
        $client = Client::factory()->create();
        $this->account($client, Platform::Instagram, 'shared');
        $other = $this->account($client, Platform::Instagram, 'own');

        $this->assertSame('own', $this->resolve($other, relinking: true));
    }

    public function test_a_read_never_moves_an_account_that_is_already_linked(): void
    {
        // Analytics and inbox reads resolve the same profile; moving one would read the wrong
        // vendor profile for an account whose link lives on the old one.
        $client = Client::factory()->create();
        $this->account($client, Platform::Instagram, 'shared');
        $tiktok = $this->account($client, Platform::TikTok, 'own');

        $this->assertSame('own', $this->resolve($tiktok));
    }

    public function test_another_clients_profile_is_never_reused(): void
    {
        $mine = Client::factory()->create();
        $theirs = Client::factory()->create();
        $this->account($theirs, Platform::TikTok, 'their-profile');

        $this->assertNotSame('their-profile', $this->resolve($this->account($mine, Platform::Instagram)));
    }

    public function test_a_deleted_account_does_not_keep_a_platform_slot_reserved(): void
    {
        $client = Client::factory()->create();
        $this->account($client, Platform::Instagram, 'shared')->delete();
        $replacement = $this->account($client, Platform::Instagram);

        // Re-linking Instagram replaces whatever the vendor still holds in that slot.
        $this->assertSame('shared', $this->resolve($replacement));
    }
}
