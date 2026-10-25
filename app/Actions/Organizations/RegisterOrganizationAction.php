<?php

namespace App\Actions\Organizations;

use App\Actions\Campaigns\SeedStarterCampaignTemplatesAction;
use App\Actions\TaxRates\SeedDefaultTaxRatesAction;
use App\Actions\Workspace\CreatePrimaryBrandAction;
use App\Enums\BrandKind;
use App\Enums\OrganizationType;
use App\Enums\Role;
use App\Mail\OrganizationWelcomeMail;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Step 15.2.1 — the first real "create an organization" path: an `Organization` and its first
 * `Role::Owner` user are created together in one transaction, so a half-created org/owner pair
 * can never exist.
 *
 * Two callers share it:
 * - the public signup (`/register-organization` and `/start`) passes the owner's chosen password.
 *   A single welcome email carries the one link they need: a signed email-verification URL. We do
 *   **not** fire Laravel's `Registered` event here, because its `SendEmailVerificationNotification`
 *   listener would send a *second*, separate verification email — the whole point of this path is
 *   that the owner receives exactly one email with exactly one button;
 * - a super admin onboarding an org on a customer's behalf (Step 15.3) passes no password and
 *   `$onboardedBy`: the owner gets a random password plus a "set your password" link in the
 *   welcome email — that link is the only way in, so it also counts as email verification.
 *
 * Either way the `OrganizationWelcomeMail` is queued once the transaction has committed.
 *
 * Step B-2.1 — the action is workspace-type-aware: callers may pass `type` (default `agency`, so
 * existing callers are unchanged). For a `business` signup it creates the single primary brand via
 * `CreatePrimaryBrandAction` **inside the same transaction**, so a business org can never exist
 * without its brand.
 */
class RegisterOrganizationAction
{
    public function __construct(
        private readonly CreatePrimaryBrandAction $createPrimaryBrand = new CreatePrimaryBrandAction,
    ) {}

    /**
     * @param  array{organization_name: string, default_timezone: string, default_currency: string, name: string, email: string, password?: string|null, type?: string|OrganizationType|null, brand_kind?: string|BrandKind|null}  $data
     */
    public function __invoke(array $data, ?User $onboardedBy = null): User
    {
        $password = $data['password'] ?? null;
        $selfServe = filled($password);
        $type = $this->resolveType($data['type'] ?? null);
        $brandKind = $this->resolveBrandKind($data['brand_kind'] ?? null);

        $owner = DB::transaction(function () use ($data, $password, $selfServe, $type, $brandKind) {
            $organization = Organization::create([
                'name' => trim($data['organization_name']),
                'slug' => $this->uniqueSlug($data['organization_name']),
                'type' => $type,
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

            // Step B-2.1: a business workspace owns exactly one primary brand, created here so the
            // org + owner + brand (+ its empty Brand Brain) either all exist or none do.
            if ($type === OrganizationType::Business) {
                ($this->createPrimaryBrand)($organization, $organization->name, null, $brandKind);
            }

            return $owner;
        });

        $setPasswordUrl = $selfServe ? null : route('password.reset', [
            'token' => Password::broker()->createToken($owner),
            'email' => $owner->email,
        ]);

        // Self-serve owners verify their email by clicking the single button in the welcome email.
        // The link is generous-lived (48h) so a welcome email opened the next day still works; if it
        // ever lapses, the signed-in owner is bounced to the verification notice and can resend.
        $verifyUrl = $selfServe ? $this->verificationUrl($owner) : null;

        Mail::to($owner->email)->queue(new OrganizationWelcomeMail($owner, $setPasswordUrl, $verifyUrl));

        Log::info('Organization registered', [
            'org_id' => $owner->org_id,
            'user_id' => $owner->id,
            'owner_email' => $owner->email,
            'type' => $type->value,
            'source' => $selfServe ? 'self_serve' : 'super_admin',
            'onboarded_by' => $onboardedBy?->id,
        ]);

        return $owner;
    }

    /**
     * Build the same signed `verification.verify` URL Laravel's `VerifyEmail` notification would,
     * so the welcome email's button both proves inbox ownership and marks the address verified —
     * but with a 48-hour window rather than the default 60 minutes (this is a welcome email, not a
     * just-requested resend).
     */
    private function verificationUrl(User $owner): string
    {
        return URL::temporarySignedRoute(
            'verification.verify',
            now()->addHours(48),
            ['id' => $owner->getKey(), 'hash' => sha1($owner->getEmailForVerification())],
        );
    }

    private function resolveType(string|OrganizationType|null $type): OrganizationType
    {
        if ($type instanceof OrganizationType) {
            return $type;
        }

        return $type !== null ? OrganizationType::from($type) : OrganizationType::Agency;
    }

    private function resolveBrandKind(string|BrandKind|null $brandKind): ?BrandKind
    {
        if ($brandKind instanceof BrandKind) {
            return $brandKind;
        }

        return $brandKind !== null ? BrandKind::from($brandKind) : null;
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
