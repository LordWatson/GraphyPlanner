<?php

namespace App\Actions\Organizations;

use App\Actions\Campaigns\SeedStarterCampaignTemplatesAction;
use App\Actions\TaxRates\SeedDefaultTaxRatesAction;
use App\Enums\Role;
use App\Mail\OrganizationWelcomeMail;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * Step 15.2.1 — the first real "create an organization" path: an `Organization` and its first
 * `Role::Owner` user are created together in one transaction, so a half-created org/owner pair
 * can never exist.
 *
 * Two callers share it:
 * - the public signup (`/register-organization`) passes the owner's chosen password; Fortify's
 *   email verification is then triggered through the `Registered` event;
 * - a super admin onboarding an org on a customer's behalf (Step 15.3) passes no password and
 *   `$onboardedBy`: the owner gets a random password plus a "set your password" link in the
 *   welcome email — that link is the only way in, so it also counts as email verification.
 *
 * Either way the `OrganizationWelcomeMail` is queued once the transaction has committed.
 */
class RegisterOrganizationAction
{
    /**
     * @param  array{organization_name: string, default_timezone: string, default_currency: string, name: string, email: string, password?: string|null}  $data
     */
    public function __invoke(array $data, ?User $onboardedBy = null): User
    {
        $password = $data['password'] ?? null;
        $selfServe = filled($password);

        $owner = DB::transaction(function () use ($data, $password, $selfServe) {
            $organization = Organization::create([
                'name' => trim($data['organization_name']),
                'slug' => $this->uniqueSlug($data['organization_name']),
                'default_timezone' => $data['default_timezone'],
                'default_currency' => strtoupper($data['default_currency']),
            ]);

            $owner = User::create([
                'name' => trim($data['name']),
                'email' => Str::lower(trim($data['email'])),
                'password' => $selfServe ? $password : Str::password(40),
                'org_id' => $organization->id,
                'role' => Role::Owner,
            ]);

            if (! $selfServe) {
                $owner->markEmailAsVerified();
            }

            // Campaigns enhancement C-3.4: three starter templates to begin with.
            (new SeedStarterCampaignTemplatesAction)($organization);

            // Step M-4.1: UK + UAE starter tax rates, editable in Settings.
            (new SeedDefaultTaxRatesAction)($organization);

            return $owner;
        });

        $setPasswordUrl = $selfServe ? null : route('password.reset', [
            'token' => Password::broker()->createToken($owner),
            'email' => $owner->email,
        ]);

        if ($selfServe) {
            event(new Registered($owner));
        }

        Mail::to($owner->email)->queue(new OrganizationWelcomeMail($owner, $setPasswordUrl));

        Log::info('Organization registered', [
            'org_id' => $owner->org_id,
            'user_id' => $owner->id,
            'owner_email' => $owner->email,
            'source' => $selfServe ? 'self_serve' : 'super_admin',
            'onboarded_by' => $onboardedBy?->id,
        ]);

        return $owner;
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'organization';
        $slug = $base;
        $suffix = 2;

        while ($slug === Organization::PREVIEW_SLUG || Organization::query()->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }
}
