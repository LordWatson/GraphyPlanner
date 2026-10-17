<?php

namespace App\Actions\AI\Images;

use App\Enums\ImageGenerationStatus;
use App\Enums\ImageQualityTier;
use App\Enums\ImageVariantKind;
use App\Enums\ImageVariantStatus;
use App\Exceptions\AI\ImageGenerationRateLimitedException;
use App\Jobs\PrepareImageGenerationJob;
use App\Models\AiImageGeneration;
use App\Models\Client;
use App\Models\Organization;
use App\Models\User;
use App\Services\AI\Images\ImageProviderRouter;
use App\Services\AI\Images\ImageQuotaGuard;
use App\Support\AI\Images\GenerateImageSetData;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Step 16.7: one "Generate" click. Checks the per-org rate limiter and the monthly quota, then in
 * one transaction creates the AiImageGeneration and its N queued variants, and dispatches
 * PrepareImageGenerationJob on the `ai-images` queue after commit. The job drafts (or fetches
 * the cached) brief and fans the variants out as a Bus batch, so this request returns in
 * milliseconds and the studio fills in tile by tile.
 *
 * Idempotent per `requestId`: a double submit returns the first set and never queues twice.
 */
class GenerateImageSetAction
{
    public function __construct(
        private readonly ImageQuotaGuard $quota,
        private readonly ImageProviderRouter $router,
    ) {}

    public function __invoke(Client $client, User $user, GenerateImageSetData $data): AiImageGeneration
    {
        $existing = $this->existing($client, $data->requestId);

        if ($existing !== null) {
            return $existing;
        }

        $this->throttle($client);

        /** @var Organization $organization */
        $organization = $client->organization;
        $variantCount = max(1, min($data->variantCount, (int) config('ai.images.max_variants', 4)));
        $this->quota->assertCanGenerate($organization, $variantCount);

        $provider = $this->router->select($data->withHeadline);

        try {
            $generation = DB::transaction(function () use ($client, $user, $data, $variantCount, $provider) {
                $generation = AiImageGeneration::create([
                    'uuid' => (string) Str::uuid(),
                    'org_id' => $client->org_id,
                    'client_id' => $client->id,
                    'ai_post_idea_id' => $data->idea?->id,
                    'post_id' => $data->post->id ?? $data->idea?->post_id,
                    'requested_by' => $user->id,
                    'request_id' => $data->requestId,
                    'art_direction' => $data->artDirection(),
                    'provider' => $provider->name(),
                    'model' => $provider->model(ImageQualityTier::Draft),
                    'quality_tier' => ImageQualityTier::Draft,
                    'aspect_ratio' => $data->aspectRatio,
                    'variant_count' => $variantCount,
                    'status' => ImageGenerationStatus::Queued,
                ]);

                // Distinct, well-spread seeds so a set shows real range, not near-duplicates.
                $baseSeed = random_int(1, 1_000_000);

                for ($position = 0; $position < $variantCount; $position++) {
                    $generation->variants()->create([
                        'org_id' => $client->org_id,
                        'position' => $position,
                        'kind' => ImageVariantKind::Generate,
                        'status' => ImageVariantStatus::Queued,
                        'quality_tier' => ImageQualityTier::Draft,
                        'idempotency_key' => "{$generation->uuid}:generate:{$position}",
                        'seed' => $baseSeed + $position * 7919,
                    ]);
                }

                return $generation;
            });
        } catch (UniqueConstraintViolationException) {
            // Two simultaneous submits of the same click: the other one won the insert.
            return $this->existing($client, $data->requestId) ?? throw new RuntimeException('Image set request conflict');
        }

        Log::info('AI image set requested', [
            'org_id' => $client->org_id,
            'client_id' => $client->id,
            'generation_id' => $generation->id,
            'ai_post_idea_id' => $data->idea?->id,
            'post_id' => $generation->post_id,
            'user_id' => $user->id,
            'provider' => $provider->name(),
            'aspect_ratio' => $data->aspectRatio->value,
            'variant_count' => $variantCount,
        ]);

        PrepareImageGenerationJob::dispatch($generation->id);

        return $generation;
    }

    private function existing(Client $client, string $requestId): ?AiImageGeneration
    {
        return AiImageGeneration::query()
            ->where('client_id', $client->id)
            ->where('request_id', $requestId)
            ->first();
    }

    /**
     * Per-org limiter (`ai.images.sets_per_minute`) so one burst can't flood the vendor or the queue.
     */
    private function throttle(Client $client): void
    {
        $key = 'ai-images:org:'.$client->org_id;
        $limit = max(1, (int) config('ai.images.sets_per_minute', 10));

        if (RateLimiter::tooManyAttempts($key, $limit)) {
            $retryAfter = RateLimiter::availableIn($key);

            Log::warning('AI image set rate limited', ['org_id' => $client->org_id, 'client_id' => $client->id, 'retry_after' => $retryAfter]);

            throw new ImageGenerationRateLimitedException(max(1, $retryAfter));
        }

        RateLimiter::hit($key, 60);
    }
}
