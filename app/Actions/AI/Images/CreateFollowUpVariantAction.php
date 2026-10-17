<?php

namespace App\Actions\AI\Images;

use App\Enums\ImageQualityTier;
use App\Enums\ImageVariantKind;
use App\Enums\ImageVariantStatus;
use App\Jobs\GenerateImageVariantJob;
use App\Models\AiImageVariant;
use App\Models\Organization;
use App\Models\User;
use App\Services\AI\Images\ImageQuotaGuard;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Shared pipeline for the Step 16.8 iterate actions (refine, regenerate, make final): checks the
 * quota (each call counts as one image), creates the child variant in the same set with
 * `parent_variant_id` lineage and an idempotency key derived from the client's request id, and
 * queues one GenerateImageVariantJob after commit. Repeating a request id returns the same child.
 */
class CreateFollowUpVariantAction
{
    public function __construct(
        private readonly ImageQuotaGuard $quota,
        private readonly RecomputeImageGenerationStatusAction $recompute,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes  extra variant columns (e.g. instruction)
     */
    public function __invoke(AiImageVariant $parent, User $user, ImageVariantKind $kind, string $requestId, ImageQualityTier $tier, array $attributes = []): AiImageVariant
    {
        $key = "{$parent->generation->uuid}:{$kind->value}:{$parent->id}:{$requestId}";

        $existing = AiImageVariant::query()->where('idempotency_key', $key)->first();

        if ($existing !== null) {
            return $existing;
        }

        if ($kind !== ImageVariantKind::Regenerate && ($parent->status !== ImageVariantStatus::Ready || $parent->asset_id === null)) {
            throw new InvalidArgumentException('Only a ready image can be refined or made final.');
        }

        $generation = $parent->generation;

        /** @var Organization $organization */
        $organization = $generation->organization;
        $this->quota->assertCanGenerate($organization, 1);

        try {
            $child = DB::transaction(function () use ($parent, $generation, $kind, $key, $tier, $attributes) {
                $position = (int) $generation->variants()->max('position') + 1;

                $child = $generation->variants()->create([
                    'org_id' => $generation->org_id,
                    'position' => $position,
                    'kind' => $kind,
                    'status' => ImageVariantStatus::Queued,
                    'quality_tier' => $tier,
                    'parent_variant_id' => $parent->id,
                    'idempotency_key' => $key,
                    'seed' => $kind === ImageVariantKind::Final ? $parent->seed : random_int(1, 1_000_000),
                    ...$attributes,
                ]);

                // A regenerated tile replaces the one it came from.
                if ($kind === ImageVariantKind::Regenerate) {
                    $parent->update(['discarded_at' => now()]);
                }

                return $child;
            });
        } catch (UniqueConstraintViolationException) {
            return AiImageVariant::query()->where('idempotency_key', $key)->firstOrFail();
        }

        Log::info('AI image follow-up requested', [
            'org_id' => $generation->org_id,
            'client_id' => $generation->client_id,
            'generation_id' => $generation->id,
            'variant_id' => $child->id,
            'parent_variant_id' => $parent->id,
            'kind' => $kind->value,
            'tier' => $tier->value,
            'user_id' => $user->id,
        ]);

        ($this->recompute)($generation);

        GenerateImageVariantJob::dispatch($child->id);

        return $child;
    }
}
