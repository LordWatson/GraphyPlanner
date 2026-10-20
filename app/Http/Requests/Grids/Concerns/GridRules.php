<?php

namespace App\Http\Requests\Grids\Concerns;

use App\Enums\ImageVariantStatus;
use App\Enums\Platform;
use App\Models\GridPlan;
use Illuminate\Database\Query\Builder;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Phase 18 — the ownership rules every grid write shares: referenced records must belong to the
 * plan's client (a bare `exists:` would accept another organization's ids).
 */
trait GridRules
{
    protected function plan(): GridPlan
    {
        /** @var GridPlan $plan */
        $plan = $this->route('grid');

        return $plan;
    }

    /**
     * A live asset of this client.
     */
    protected function clientAsset(int $clientId): Exists
    {
        return Rule::exists('assets', 'id')->where('client_id', $clientId)->whereNull('deleted_at');
    }

    /**
     * A finished Image Studio variant of this client that someone picked (`selected`).
     */
    protected function selectedVariant(int $clientId): Exists
    {
        return Rule::exists('ai_image_variants', 'id')
            ->whereNotNull('selected_at')
            ->whereNotNull('asset_id')
            ->where('status', ImageVariantStatus::Ready->value)
            ->where(fn (Builder $query) => $query->whereIn(
                'generation_id',
                fn (Builder $generations) => $generations->select('id')->from('ai_image_generations')->where('client_id', $clientId),
            ));
    }

    /**
     * One of this client's Instagram accounts.
     */
    protected function instagramAccount(int $clientId): Exists
    {
        return Rule::exists('social_accounts', 'id')
            ->where('client_id', $clientId)
            ->where('platform', Platform::Instagram->value)
            ->whereNull('deleted_at');
    }
}
