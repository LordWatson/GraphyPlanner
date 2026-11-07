<?php

namespace App\Actions\SocialAccounts;

use App\Models\SocialAccount;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Resolves the Upload-Post profile ("user") a social account publishes and reports under, and
 * persists it on `social_accounts.upload_post_profile`.
 *
 * **Why this exists:** Upload-Post's plans are counted in *profiles*, and a profile holds at most
 * one connected account per platform (publishing targets `user` + `platform`, and the Facebook /
 * LinkedIn / Google Business pins are single-valued). One profile per account therefore burned a
 * plan five times over for one client with five networks; one profile per *organization* broke as
 * soon as a second brand needed the same network.
 *
 * So the unit is the **client**: every account of the same client shares its profile, and a second
 * profile is minted only when the platform slot is already taken (a client with two Instagram
 * accounts genuinely needs two profiles — the vendor cannot address both under one).
 *
 * Idempotent: an account that already has a profile keeps it, so a reconnect, a publish and an
 * analytics read always name the same vendor profile.
 */
class EnsurePublishProfileAction
{
    /**
     * @param  bool  $relinking  true only when the account is about to be linked at the vendor
     *                           again (the hosted connect flow), which is the one moment it is
     *                           safe to fold it into a sibling's profile: the new link replaces
     *                           the old one. Never pass true on a read path.
     */
    public function __invoke(SocialAccount $account, bool $relinking = false): string
    {
        $current = (string) ($account->upload_post_profile ?? '');

        if (! $relinking && $current !== '') {
            return $current;
        }

        $free = $this->siblingProfilesWithAFreeSlot($account);

        // Nothing better to move to, or this account is already on a shared profile: stay put.
        if ($current !== '' && ($free->isEmpty() || $free->contains($current))) {
            return $current;
        }

        $profile = $free->first()
            // Deterministic prefix + random suffix: readable in the vendor dashboard, collision-free.
            ?? 'alima-client-'.$account->client_id.'-'.Str::lower(Str::random(8));

        $account->forceFill(['upload_post_profile' => $profile])->save();

        Log::info('Resolved Upload-Post publish profile', [
            'social_account_id' => $account->id,
            'org_id' => $account->org_id,
            'client_id' => $account->client_id,
            'upload_post_profile' => $profile,
            'shared' => $free->isNotEmpty(),
        ]);

        return $profile;
    }

    /**
     * The profiles already in use by this client's other accounts that have no *live* account on
     * this account's platform — i.e. the ones with a free slot for it.
     *
     * Deleted accounts are counted as holders of a profile but not of a slot: re-linking a
     * platform replaces whatever the vendor still has there, so deleting and re-adding an account
     * reclaims its old profile instead of burning another one off the plan.
     *
     * @return Collection<int, string>
     */
    private function siblingProfilesWithAFreeSlot(SocialAccount $account): Collection
    {
        return SocialAccount::query()
            ->withTrashed()
            ->withoutOrgScope()
            ->where('client_id', $account->client_id)
            ->whereKeyNot($account->getKey())
            ->whereNotNull('upload_post_profile')
            ->get(['id', 'platform', 'upload_post_profile', 'deleted_at'])
            ->groupBy(fn (SocialAccount $sibling): string => (string) $sibling->upload_post_profile)
            ->reject(fn (Collection $siblings): bool => $siblings->contains(
                fn (SocialAccount $sibling): bool => $sibling->platform === $account->platform
                    && $sibling->deleted_at === null,
            ))
            ->keys()
            ->map(fn (mixed $profile): string => (string) $profile)
            ->values();
    }
}
