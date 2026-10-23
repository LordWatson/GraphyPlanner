<?php

namespace App\Http\Controllers\Business;

use App\Actions\AI\Images\GenerateImageSetAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Business\GenerateImagePresetRequest;
use App\Models\AiImageGeneration;
use App\Policies\AiImageGenerationPolicy;
use App\Services\Workspace\CurrentWorkspace;
use App\Support\AI\Images\BusinessImagePresetComposer;
use App\Support\AI\Images\BusinessImageStudioPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Step B-6.5 — the business shell's Image Studio: one-tap presets ("Today's special", "Opening
 * hours", "Sale announcement", "Quote card") that generate on-brand images through the shared
 * Phase 16 engine. Thin controller: it resolves the brand from the current workspace, authorises
 * through {@see AiImageGenerationPolicy} (Owner/Manager generate; a Creator views and
 * downloads), and delegates the brief assembly to {@see BusinessImagePresetComposer} and the
 * generation to {@see GenerateImageSetAction}. Every endpoint 404s when `ai.enabled` is off. The
 * brand-agnostic `ai-image-*` routes (poll, use-in-post, refine/regenerate/finalise/discard,
 * download) are shared with the agency studio and need no business copies.
 */
class ImageStudioController extends Controller
{
    public function index(Request $request, CurrentWorkspace $workspace, BusinessImageStudioPresenter $presenter): Response
    {
        $this->guard();
        $brand = $workspace->brand();
        $this->authorize('viewAnyForClient', [AiImageGeneration::class, $brand]);

        return Inertia::render('business/studio', [
            'imageStudio' => $presenter->present($brand, $request->user()),
        ]);
    }

    public function generate(
        GenerateImagePresetRequest $request,
        CurrentWorkspace $workspace,
        BusinessImagePresetComposer $composer,
        GenerateImageSetAction $action,
    ): RedirectResponse {
        $this->guard();
        $brand = $workspace->brand();
        $this->authorize('generate', [AiImageGeneration::class, $brand]);

        // Quota and the per-org rate limit are enforced inside the Action, which renders its own
        // friendly `imageStudio` error (so the studio page shows it without a thrown 500).
        $action($brand, $request->user(), $composer->compose(
            $brand,
            $request->preset(),
            (string) $request->validated('text'),
            $request->ratio(),
            (int) $request->validated('variant_count'),
            (string) $request->validated('request_id'),
        ));

        return back();
    }

    private function guard(): void
    {
        abort_unless((bool) config('ai.enabled', false), 404);
    }
}
