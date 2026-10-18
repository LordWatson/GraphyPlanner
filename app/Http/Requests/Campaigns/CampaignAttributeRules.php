<?php

namespace App\Http\Requests\Campaigns;

use App\Enums\CampaignBillingType;
use App\Enums\CampaignColour;
use App\Enums\CampaignObjective;
use App\Enums\Platform;
use App\Enums\Role;
use App\Models\Client;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * Step C-0.2 — the campaign fields shared by create and update, so both forms validate the same
 * way: the owner must be staff in the client's organization, the code is unique per client
 * (soft-deleted campaigns keep theirs, matching the database's unique index), and colour,
 * objective and platforms come from their enums.
 */
final class CampaignAttributeRules
{
    /**
     * @return array<string, mixed>
     */
    public static function rules(Client $client, ?int $ignoreCampaignId = null): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => [
                'nullable', 'string', 'max:12', 'regex:/^[A-Z0-9][A-Z0-9-]*$/',
                Rule::unique('campaigns', 'code')
                    ->where('client_id', $client->id)
                    ->ignore($ignoreCampaignId),
            ],
            'owner_id' => [
                'nullable', 'integer',
                Rule::exists('users', 'id')
                    ->where('org_id', $client->org_id)
                    ->whereIn('role', self::staffRoles()),
            ],
            'objective' => ['nullable', new Enum(CampaignObjective::class)],
            'colour' => ['nullable', new Enum(CampaignColour::class)],
            'platforms' => ['nullable', 'array'],
            'platforms.*' => ['distinct', new Enum(Platform::class)],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'goal' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:10000'],
            'hashtags' => ['nullable', 'array', 'max:30'],
            'hashtags.*' => ['string', 'max:100', 'regex:/^[\pL\pN_]+$/u'],
            'default_link_url' => ['nullable', 'url:http,https', 'max:2048'],
            'utm_campaign' => ['nullable', 'string', 'max:100', 'regex:/^[a-z0-9][a-z0-9_-]*$/'],
            // Step C-8.1: only a fixed fee carries an amount (Owner/Strategist are the only roles
            // that reach these requests, matching Phase 17's revenue rule).
            'billing_type' => ['nullable', new Enum(CampaignBillingType::class)],
            'fee_amount' => [
                'nullable', 'numeric', 'min:0', 'max:9999999999',
                Rule::prohibitedIf(fn () => request()->input('billing_type') !== CampaignBillingType::FixedFee->value),
            ],
        ];
    }

    /**
     * Roles that count as studio staff (anyone but a client contact or a platform admin).
     *
     * @return list<string>
     */
    public static function staffRoles(): array
    {
        return [Role::Owner->value, Role::Strategist->value, Role::Designer->value, Role::Viewer->value];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'code.regex' => 'Use capital letters, numbers and dashes, e.g. SPR26.',
            'code.unique' => 'This client already has a campaign with that code.',
            'owner_id.exists' => 'Pick someone on your team.',
            'hashtags.*.regex' => 'Hashtags can only use letters, numbers and underscores.',
            'utm_campaign.regex' => 'Use lower-case letters, numbers, dashes and underscores, e.g. spring-launch.',
            'end_date.after_or_equal' => 'The end date can’t be before the start date.',
            'fee_amount.prohibited' => 'Only a fixed-fee campaign has a fee. Clear it, or switch to fixed fee.',
        ];
    }

    /**
     * Normalise free-typed input before validation: upper-case codes, strip `#` and duplicates
     * from hashtags, lower-case the UTM name, and turn empty strings into nulls.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function normalise(array $input): array
    {
        $out = [];

        if (array_key_exists('code', $input) && is_string($input['code'])) {
            $code = strtoupper(trim($input['code']));
            $out['code'] = $code === '' ? null : $code;
        }

        if (array_key_exists('hashtags', $input) && is_array($input['hashtags'])) {
            $tags = [];
            $seen = [];

            foreach ($input['hashtags'] as $tag) {
                if (! is_string($tag)) {
                    $tags[] = $tag;

                    continue;
                }

                $clean = ltrim(trim($tag), '#');
                $key = Str::lower($clean);

                if ($clean !== '' && ! isset($seen[$key])) {
                    $seen[$key] = true;
                    $tags[] = $clean;
                }
            }

            $out['hashtags'] = $tags;
        }

        if (array_key_exists('utm_campaign', $input) && is_string($input['utm_campaign'])) {
            $utm = trim($input['utm_campaign']);
            $out['utm_campaign'] = $utm === '' ? null : Str::lower($utm);
        }

        foreach (['objective', 'colour', 'default_link_url', 'owner_id', 'start_date', 'end_date', 'billing_type', 'fee_amount'] as $key) {
            if (array_key_exists($key, $input) && $input[$key] === '') {
                $out[$key] = null;
            }
        }

        return $out;
    }
}
