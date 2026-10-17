<?php

namespace App\Http\Controllers;

use App\Actions\AI\Images\DiscardImageVariantAction;
use App\Actions\AI\Images\FinalizeImageVariantAction;
use App\Actions\AI\Images\GenerateImageSetAction;
use App\Actions\AI\Images\RefineImageVariantAction;
use App\Actions\AI\Images\RegenerateImageVariantAction;
use App\Actions\AI\Images\UseGeneratedImagesInPostAction;
use App\Http\Requests\GenerateImagesRequest;
use App\Http\Requests\ImageVariantActionRequest;
use App\Http\Requests\UseImagesInPostRequest;
use App\Models\AiImageGeneration;
use App\Models\AiImageVariant;
use App\Models\AiPostIdea;
use App\Models\Client;
use App\Models\Post;
use App\Support\AI\Images\ImageStudioPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Thin controller for the AI Image Studio (Phase 16). Every endpoint 404s when `ai.enabled` is off
 * and is authorized through AiImageGenerationPolicy (Owner/Strategist write, Designer read,
 * ClientReviewer/Viewer nothing). Writes call one Action and redirect back so the studio's
 * `imageStudio` prop reloads; quota/rate-limit refusals render themselves as friendly errors.
 */
class AiImageController extends Controller
{
    /**
     * `POST ai-post-ideas/{aiPostIdea}/images`: generate a set for an idea (its draft, if any, is linked).
     */
    public function generateForIdea(GenerateImagesRequest $request, AiPostIdea $aiPostIdea, GenerateImageSetAction $action): RedirectResponse
    {
        $this->guard();
        $this->authorize('generate', [AiImageGeneration::class, $aiPostIdea->client]);

        $action($aiPostIdea->client, $request->user(), $request->toData(idea: $aiPostIdea));

        return back();
    }

    /**
     * `POST clients/{client}/ai/images`: generate a set for a client, optionally for one of its posts.
     */
    public function generate(GenerateImagesRequest $request, Client $client, GenerateImageSetAction $action): RedirectResponse
    {
        $this->guard();
        $this->authorize('generate', [AiImageGeneration::class, $client]);

        $postId = $request->validated('post_id');
        $post = $postId !== null ? Post::query()->where('client_id', $client->id)->whereKey($postId)->firstOrFail() : null;
        $idea = $post !== null ? AiPostIdea::query()->where('post_id', $post->id)->first() : null;

        $action($client, $request->user(), $request->toData(idea: $idea, post: $post));

        return back();
    }

    /**
     * `GET ai-image-generations/{generation}`: lean status payload for polling clients.
     */
    public function show(AiImageGeneration $generation, ImageStudioPresenter $presenter): JsonResponse
    {
        $this->guard();
        $this->authorize('view', $generation);

        $generation->load(['variants.asset', 'requester:id,name']);

        return response()->json(['generation' => $presenter->generation($generation)]);
    }

    public function refine(ImageVariantActionRequest $request, AiImageVariant $variant, RefineImageVariantAction $action): RedirectResponse
    {
        $this->guardVariant($variant);

        return $this->attempt(fn () => $action($variant, $request->user(), (string) $request->validated('instruction'), (string) $request->validated('request_id')));
    }

    public function regenerate(ImageVariantActionRequest $request, AiImageVariant $variant, RegenerateImageVariantAction $action): RedirectResponse
    {
        $this->guardVariant($variant);

        return $this->attempt(fn () => $action($variant, $request->user(), (string) $request->validated('request_id')));
    }

    public function finalize(ImageVariantActionRequest $request, AiImageVariant $variant, FinalizeImageVariantAction $action): RedirectResponse
    {
        $this->guardVariant($variant);

        return $this->attempt(fn () => $action($variant, $request->user(), (string) $request->validated('request_id')));
    }

    public function discard(ImageVariantActionRequest $request, AiImageVariant $variant, DiscardImageVariantAction $action): RedirectResponse
    {
        $this->guardVariant($variant);

        $action($variant, $request->user(), $request->boolean('discarded'));

        return back();
    }

    /**
     * Designers may download (read access); the file is served with a friendly name.
     */
    public function download(AiImageVariant $variant): StreamedResponse
    {
        $this->guard();
        $this->authorize('view', $variant->generation);

        $asset = $variant->asset;
        abort_if($asset === null || $asset->disk === null || $asset->path === null, 404);

        return Storage::disk($asset->disk)->download($asset->path, $asset->original_filename);
    }

    /**
     * `POST ai-image-generations/{generation}/use`: attach the selected images to the set's post
     * (creating the idea's draft first if needed) and open it.
     */
    public function useInPost(UseImagesInPostRequest $request, AiImageGeneration $generation, UseGeneratedImagesInPostAction $action): RedirectResponse
    {
        $this->guard();
        $this->authorize('manage', $generation);

        try {
            $post = $action($generation, array_map('intval', (array) $request->validated('variant_ids')), $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['imageStudio' => $e->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Images added to the post.')]);

        return to_route('posts.edit', $post);
    }

    private function guard(): void
    {
        abort_unless((bool) config('ai.enabled', false), 404);
    }

    private function guardVariant(AiImageVariant $variant): void
    {
        $this->guard();
        $this->authorize('manage', $variant->generation);
    }

    /**
     * @param  callable(): mixed  $callback
     */
    private function attempt(callable $callback): RedirectResponse
    {
        try {
            $callback();
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['imageStudio' => $e->getMessage()]);
        }

        return back();
    }
}
