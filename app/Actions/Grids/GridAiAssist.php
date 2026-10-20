<?php

namespace App\Actions\Grids;

use App\Actions\AI\Images\GenerateImageSetAction;
use App\Contracts\AssetStorage;
use App\Contracts\GridAiProvider;
use App\Enums\GridTileKind;
use App\Enums\ImageAspectRatio;
use App\Enums\MediaRatio;
use App\Models\AiImageGeneration;
use App\Models\AiImageVariant;
use App\Models\AiUsageLog;
use App\Models\Asset;
use App\Models\GridPlan;
use App\Models\GridTile;
use App\Models\User;
use App\Services\AI\AiGuardrailService;
use App\Services\AI\DeepSeekProvider;
use App\Services\AI\Images\ImageNormalizer;
use App\Services\Grids\GridPlanService;
use App\Support\AI\Images\GenerateImageSetData;
use Illuminate\Support\Facades\Log;

/**
 * Step 18.12 — the grid planner's AI help: caption ideas per tile, and a balance check. Both are
 * suggestions only: nothing is written until someone clicks "Use" (which saves the tile through
 * `UpdateGridTileAction`), and nothing is ever reordered. Captions pass the Brand Brain
 * guardrail; each call is one `ai_usage_logs` row. No prompt, brief or key reaches props or logs.
 */
class GridAiAssist
{
    public function __construct(
        private readonly GridAiProvider $ai,
        private readonly AiGuardrailService $guardrail,
        private readonly GridPlanService $grids,
        private readonly ImageNormalizer $images,
        private readonly AssetStorage $storage,
        private readonly GenerateImageSetAction $generate,
        private readonly UpdateGridTileAction $updateTile,
    ) {}

    /**
     * @return array<int, list<string>> caption ideas per tile id (tiles that are placeholders are skipped)
     */
    public function suggestCaptions(GridPlan $plan, User $user): array
    {
        $this->grids->load($plan);
        $plan->loadMissing('client.brandBrain');
        $startedAt = microtime(true);

        $tiles = $plan->tiles
            ->filter(fn (GridTile $tile) => $tile->kind !== GridTileKind::Placeholder || filled($tile->team_note))
            ->map(fn (GridTile $tile) => [
                'tile_id' => $tile->id,
                'position' => $tile->position,
                'kind' => $tile->kind->value,
                'description' => $this->describe($tile),
            ])
            ->all();
        $tiles = array_values($tiles);

        $suggestions = $tiles === [] ? [] : $this->ai->suggestTileCaptions($plan, $tiles);
        $brandBrain = $plan->client->brandBrain;

        // Drop any idea using a banned phrase from the Brand Brain.
        $clean = [];

        foreach ($suggestions as $tileId => $captions) {
            $kept = array_values(array_map(
                fn (string $caption) => $this->guardrail->scrub($caption),
                array_filter($captions, fn (string $caption) => $this->guardrail->bannedPhrasesIn($brandBrain, $caption) === []),
            ));

            if ($kept !== []) {
                $clean[$tileId] = $kept;
            }
        }

        $this->record($plan, $user, 'suggestTileCaptions', $clean !== [], $startedAt);

        Log::info('Grid caption ideas suggested', [
            'grid_plan_id' => $plan->id,
            'org_id' => $plan->org_id,
            'user_id' => $user->id,
            'tiles' => count($clean),
            'dropped' => count($suggestions) - count($clean),
        ]);

        return $clean;
    }

    /**
     * @return list<string>
     */
    public function checkBalance(GridPlan $plan, User $user): array
    {
        $this->grids->load($plan);
        $layout = $this->grids->layout($plan);
        $startedAt = microtime(true);

        $tiles = $plan->tiles->map(fn (GridTile $tile) => [
            'position' => $tile->position,
            'row' => $layout->tile($tile->id)['row'] ?? 1,
            'kind' => $tile->kind->value,
            'description' => $this->describe($tile),
            'colours' => $this->colours($tile),
        ])->all();
        $tiles = array_values($tiles);

        $notes = $tiles === [] ? [] : $this->ai->checkGridBalance($plan, $tiles);
        $notes = array_values(array_filter($notes, fn (string $note) => $this->guardrail->bannedPhrasesIn($plan->client->brandBrain, $note) === []));

        $this->record($plan, $user, 'checkGridBalance', $notes !== [], $startedAt);

        Log::info('Grid balance checked', ['grid_plan_id' => $plan->id, 'org_id' => $plan->org_id, 'user_id' => $user->id, 'notes' => count($notes)]);

        return $notes;
    }

    /**
     * "Fill this slot": an Image Studio set at the grid's 3:4, art-directed from the placeholder's
     * note (or caption idea) and the client's Brand Brain. Nothing lands on the tile until someone
     * picks one (`useVariant`).
     */
    public function fillSlot(GridTile $tile, User $user, string $requestId): AiImageGeneration
    {
        $plan = $tile->plan;
        $plan->loadMissing('client');
        $subject = trim((string) ($tile->team_note ?: $tile->caption_idea ?: $plan->name));

        $generation = ($this->generate)($plan->client, $user, new GenerateImageSetData(
            requestId: $requestId,
            aspectRatio: ImageAspectRatio::Classic_3_4,
            variantCount: min(2, max(1, (int) config('ai.images.max_variants', 4))),
            overrides: ['subject' => mb_substr($subject, 0, 500)],
        ));

        Log::info('Grid slot fill requested', ['grid_plan_id' => $plan->id, 'grid_tile_id' => $tile->id, 'user_id' => $user->id, 'generation_id' => $generation->id]);

        return $generation;
    }

    /**
     * Put a finished Image Studio image on a tile. The variant is marked picked (`selected_at`),
     * so the studio's housekeeping keeps its file.
     */
    public function useVariant(GridTile $tile, User $user, AiImageVariant $variant): GridTile
    {
        $variant->update(['selected_at' => $variant->selected_at ?? now()]);

        return ($this->updateTile)($tile, $user, [
            'kind' => GridTileKind::Image->value,
            'format' => MediaRatio::Classic->value,
            'asset_ids' => [(int) $variant->asset_id],
            'placeholder_colour' => null,
        ]);
    }

    /**
     * What the AI is told about a tile: its kind and format, the media's alt text and file names,
     * and any caption idea or placeholder note already written.
     */
    private function describe(GridTile $tile): string
    {
        $parts = [$tile->kind->label().' at '.$tile->format->value];

        foreach ($tile->assets as $asset) {
            $alt = GridPlanService::pivotRow($asset, 0)['alt_text'];
            $parts[] = $alt ?? pathinfo((string) ($asset->original_filename ?? 'image'), PATHINFO_FILENAME);
        }

        if (filled($tile->caption_idea)) {
            $parts[] = 'Idea: '.$tile->caption_idea;
        }

        if ($tile->kind === GridTileKind::Placeholder && filled($tile->team_note)) {
            $parts[] = 'Planned: '.$tile->team_note;
        }

        return mb_substr(implode('. ', $parts), 0, 600);
    }

    /**
     * @return list<string>
     */
    private function colours(GridTile $tile): array
    {
        if ($tile->placeholder_colour !== null) {
            return [$tile->placeholder_colour->label()];
        }

        $asset = $this->grids->thumbnailAsset($tile);

        if (! $asset instanceof Asset || blank($asset->disk)) {
            return [];
        }

        $path = filled($asset->thumbnail_path) ? (string) $asset->thumbnail_path : (string) $asset->path;
        $bytes = $path !== '' ? $this->storage->contents((string) $asset->disk, $path) : null;

        return $bytes === null ? [] : $this->images->dominantColours($bytes);
    }

    private function record(GridPlan $plan, User $user, string $method, bool $succeeded, float $startedAt): void
    {
        $isDeepSeek = $this->ai instanceof DeepSeekProvider;

        AiUsageLog::create([
            'org_id' => $plan->org_id,
            'client_id' => $plan->client_id,
            'campaign_id' => $plan->campaign_id,
            'user_id' => $user->id,
            'provider' => $isDeepSeek ? 'deepseek' : 'null',
            'model' => $isDeepSeek ? (string) config('ai.deepseek.model', 'deepseek-chat') : 'none',
            'method' => $method,
            'succeeded' => $succeeded,
            'error_code' => $succeeded ? null : 'empty_response',
            'cost_estimate' => $isDeepSeek && $succeeded ? (float) config('ai.deepseek.estimated_cost_per_call', 0) : 0,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);
    }
}
