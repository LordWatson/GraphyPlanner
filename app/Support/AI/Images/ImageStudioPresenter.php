<?php

namespace App\Support\AI\Images;

use App\Contracts\AssetStorage;
use App\Enums\ImageAspectRatio;
use App\Enums\ImageVariantStatus;
use App\Enums\Platform;
use App\Enums\Role;
use App\Models\AiImageGeneration;
use App\Models\AiImageVariant;
use App\Models\AiPostIdea;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Post;
use App\Models\User;
use App\Services\AI\Images\ImageQuotaGuard;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Builds the `imageStudio` Inertia prop for the idea page and the post editor (Step 16.9). Carries
 * only what the studio renders: formats, quota, the editable art-direction fields, palette
 * swatches, reference thumbnails and the sets' tiles. Never the full brief, prompt, avoid list,
 * provider/model, costs or keys.
 */
class ImageStudioPresenter
{
    private const RECENT_SETS = 8;

    public function __construct(
        private readonly ImageQuotaGuard $quota,
        private readonly AssetStorage $storage,
        private readonly BrandImageBriefFactory $briefs,
        private readonly ImageBriefInputResolver $inputs,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forIdea(AiPostIdea $idea, User $user): array
    {
        return $this->present($idea->client, $user, $idea, $idea->post);
    }

    /**
     * @return array<string, mixed>
     */
    public function forPost(Post $post, User $user): array
    {
        $idea = AiPostIdea::query()->where('post_id', $post->id)->first();

        return $this->present($post->client, $user, $idea, $post);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Client $client, User $user, ?AiPostIdea $idea, ?Post $post): array
    {
        $client->loadMissing('brandBrain.referenceAssets.asset', 'organization');
        $input = $this->inputs->resolve($idea, $post);
        $defaults = $this->briefs->defaults($client, $input);

        /** @var Organization $organization */
        $organization = $client->organization;
        $generations = $this->recentGenerations($client, $idea, $post);

        return [
            'canGenerate' => $user->org_id === $client->org_id && in_array($user->role, [Role::Owner, Role::Strategist], true),
            'target' => [
                'type' => $idea !== null ? 'idea' : 'post',
                'ideaId' => $idea?->id,
                'postId' => $post->id ?? $idea?->post_id,
                'clientId' => $client->id,
                'clientName' => $client->name,
                'title' => $input->title,
                'caption' => $input->caption,
                'platforms' => $input->platforms,
            ],
            'formats' => array_map(fn (ImageAspectRatio $ratio) => [
                'value' => $ratio->value,
                'label' => $ratio->label(),
                'quotient' => $ratio->quotient(),
            ], ImageAspectRatio::cases()),
            'defaultFormat' => $this->defaultFormat($input->platforms)->value,
            'maxVariants' => (int) config('ai.images.max_variants', 4),
            'quota' => $this->quota->summary($organization),
            'brandDefaults' => [
                'subject' => $defaults->subject,
                'mood' => $defaults->mood,
                'style' => $defaults->style,
                'composition' => $defaults->composition,
            ],
            'palette' => $this->briefs->palette($client->brandBrain),
            'references' => ($client->brandBrain->referenceAssets ?? collect())
                ->filter(fn ($reference) => $reference->asset !== null)
                ->map(fn ($reference) => [
                    'assetId' => $reference->asset_id,
                    'url' => $reference->asset?->url,
                    'role' => $reference->role->value,
                    'roleLabel' => $reference->role->label(),
                ])
                ->values()
                ->all(),
            'generations' => $generations->map(fn (AiImageGeneration $generation) => $this->generation($generation))->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function generation(AiImageGeneration $generation): array
    {
        $brief = $generation->briefDto();
        $art = $generation->art_direction ?? [];

        return [
            'id' => $generation->id,
            'status' => $generation->status->value,
            'statusLabel' => $generation->status->label(),
            'settled' => $generation->status->isSettled(),
            'format' => $generation->aspect_ratio->value,
            'formatLabel' => $generation->aspect_ratio->label(),
            'quotient' => $generation->aspect_ratio->quotient(),
            'variantCount' => $generation->variant_count,
            'requestedBy' => $generation->requester?->name,
            'createdAt' => $generation->created_at?->toIso8601String(),
            // The editable art-direction fields only, as drafted (or as typed by the person).
            'artDirection' => [
                'subject' => $brief->subject ?? ($art['subject'] ?? null),
                'mood' => $brief->mood ?? ($art['mood'] ?? null),
                'style' => $brief->style ?? ($art['style'] ?? null),
                'composition' => $brief->composition ?? ($art['composition'] ?? null),
                'excludedColours' => array_values($art['excludedColours'] ?? []),
                'withHeadline' => (bool) ($art['withHeadline'] ?? false),
                'headline' => $art['headline'] ?? null,
            ],
            // Exactly what the person typed for this set, so "Generate again" can resend it
            // unchanged (same brief_hash → cached brief, zero extra text-model calls).
            'overrides' => [
                'subject' => $art['subject'] ?? null,
                'mood' => $art['mood'] ?? null,
                'style' => $art['style'] ?? null,
                'composition' => $art['composition'] ?? null,
            ],
            'variants' => $generation->variants
                ->map(fn (AiImageVariant $variant) => $this->variant($variant, $brief?->subject))
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function variant(AiImageVariant $variant, ?string $alt): array
    {
        $asset = $variant->asset;

        return [
            'id' => $variant->id,
            'position' => $variant->position,
            'kind' => $variant->kind->value,
            'kindLabel' => $variant->kind->label(),
            'status' => $variant->status->value,
            'statusLabel' => $variant->status->label(),
            'tier' => $variant->quality_tier->value,
            'parentId' => $variant->parent_variant_id,
            'instruction' => $variant->instruction,
            'previewUrl' => $variant->preview_disk !== null && $variant->preview_path !== null
                ? $this->storage->url($variant->preview_disk, $variant->preview_path)
                : $asset?->url,
            'url' => $asset?->url,
            'assetId' => $asset?->id,
            'width' => $asset?->width,
            'height' => $asset?->height,
            'alt' => $alt !== null && $alt !== '' ? $alt : 'AI generated image',
            'error' => $variant->error_code?->userMessage(),
            'selected' => $variant->selected_at !== null,
            'discarded' => $variant->discarded_at !== null,
            'pending' => $variant->status->isPending(),
            'ready' => $variant->status === ImageVariantStatus::Ready && $asset !== null,
        ];
    }

    /**
     * @param  array<int, string>  $platforms
     */
    private function defaultFormat(array $platforms): ImageAspectRatio
    {
        foreach ($platforms as $value) {
            $platform = Platform::tryFrom(strtolower($value));

            if ($platform !== null) {
                return ImageAspectRatio::forPlatform($platform);
            }
        }

        return ImageAspectRatio::Square_1_1;
    }

    /**
     * @return Collection<int, AiImageGeneration>
     */
    private function recentGenerations(Client $client, ?AiPostIdea $idea, ?Post $post)
    {
        $postId = $post->id ?? $idea?->post_id;

        return AiImageGeneration::query()
            ->where('client_id', $client->id)
            ->where(function (Builder $query) use ($idea, $postId) {
                $query->whereRaw('1 = 0');

                if ($idea !== null) {
                    $query->orWhere('ai_post_idea_id', $idea->id);
                }

                if ($postId !== null) {
                    $query->orWhere('post_id', $postId);
                }
            })
            ->with(['variants.asset', 'requester:id,name'])
            ->latest('id')
            ->limit(self::RECENT_SETS)
            ->get();
    }
}
