<?php

namespace App\Support\AI\Images;

use App\Enums\BusinessImagePreset;
use App\Enums\ImageAspectRatio;
use App\Enums\Role;
use App\Models\AiImageGeneration;
use App\Models\Client;
use App\Models\Organization;
use App\Models\User;
use App\Services\AI\Images\ImageQuotaGuard;

/**
 * Step B-6.5 — builds the `imageStudio` Inertia prop for the business shell's Image Studio page.
 * Carries the one-tap presets, the format choices, the monthly quota and the brand's own
 * standalone preset sets (those not tied to an idea or post). Each set is serialised through the
 * shared {@see ImageStudioPresenter::generation()} so a business set carries exactly the same
 * safe fields (no brief, prompt, cost, provider or keys) the agency studio does.
 */
class BusinessImageStudioPresenter
{
    private const RECENT_SETS = 8;

    public function __construct(
        private readonly ImageStudioPresenter $studio,
        private readonly ImageQuotaGuard $quota,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function present(Client $brand, User $user): array
    {
        $brand->loadMissing('organization');

        /** @var Organization $organization */
        $organization = $brand->organization;

        // Only the brand's own preset sets — standalone generations with no idea/post behind them.
        $generations = AiImageGeneration::query()
            ->where('client_id', $brand->id)
            ->whereNull('ai_post_idea_id')
            ->whereNull('post_id')
            ->with(['variants.asset', 'requester:id,name'])
            ->latest('id')
            ->limit(self::RECENT_SETS)
            ->get();

        return [
            'canGenerate' => $user->org_id === $brand->org_id
                && in_array($user->role, [Role::Owner, Role::Strategist], true),
            'presets' => array_map(fn (BusinessImagePreset $preset): array => [
                'key' => $preset->value,
                'label' => $preset->label(),
                'tagline' => $preset->tagline(),
                'fieldLabel' => $preset->fieldLabel(),
                'placeholder' => $preset->placeholder(),
                'defaultFormat' => $preset->defaultFormat()->value,
            ], BusinessImagePreset::cases()),
            'formats' => array_map(fn (ImageAspectRatio $ratio): array => [
                'value' => $ratio->value,
                'label' => $ratio->label(),
                'quotient' => $ratio->quotient(),
            ], ImageAspectRatio::cases()),
            'maxVariants' => (int) config('ai.images.max_variants', 4),
            'quota' => $this->quota->summary($organization),
            'generations' => $generations
                ->map(fn (AiImageGeneration $generation): array => $this->studio->generation($generation))
                ->values()
                ->all(),
        ];
    }
}
