<?php

namespace App\Http\Controllers\Business;

use App\Actions\Business\BuildMediaKitAction;
use App\Actions\Business\GenerateMediaKitPdfAction;
use App\Actions\Business\RevokeMediaKitAction;
use App\Actions\Business\ShareMediaKitAction;
use App\Actions\Business\UpdateMediaKitAction;
use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Services\Workspace\CurrentWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response as ResponseFactory;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Step B-8.4 — the owner's media kit: edit the bio, preview exactly what a sponsor would see, turn
 * the public link on/off, and download the branded PDF. Thin: resolves the brand from
 * {@see CurrentWorkspace} and delegates to the media-kit Actions. Mutations require the brand-update
 * ability (Owner/Manager); viewing matches the Insights page.
 */
class MediaKitController extends Controller
{
    public function index(Request $request, CurrentWorkspace $workspace, BuildMediaKitAction $build): Response
    {
        $brand = $workspace->brand();
        $this->authorize('viewAny', [Post::class, $brand]);

        $kit = $build->kitFor($brand);

        return Inertia::render('business/media-kit', [
            'kit' => [
                'bio' => $kit->bio,
                'is_public' => $kit->isPublic(),
                'share_url' => $kit->isPublic() ? route('media-kit.show', $kit->share_token) : null,
                'shared_at' => $kit->shared_at?->toIso8601String(),
            ],
            'preview' => Inertia::defer(fn () => $build($kit, $brand)),
            'canManage' => $request->user()?->can('update', $brand) ?? false,
        ]);
    }

    public function update(Request $request, CurrentWorkspace $workspace, UpdateMediaKitAction $action): RedirectResponse
    {
        $brand = $workspace->brand();
        $this->authorize('update', $brand);

        $validated = $request->validate(['bio' => ['nullable', 'string', 'max:1000']]);
        $action($brand, $validated['bio'] ?? null);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Media kit saved.')]);

        return back();
    }

    public function share(CurrentWorkspace $workspace, ShareMediaKitAction $action): RedirectResponse
    {
        $brand = $workspace->brand();
        $this->authorize('update', $brand);

        $action($brand);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Public link is on. Anyone with it can view your media kit.')]);

        return back();
    }

    public function revoke(CurrentWorkspace $workspace, RevokeMediaKitAction $action): RedirectResponse
    {
        $brand = $workspace->brand();
        $this->authorize('update', $brand);

        $action($brand);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Public link switched off.')]);

        return back();
    }

    public function pdf(CurrentWorkspace $workspace, BuildMediaKitAction $build, GenerateMediaKitPdfAction $pdf): StreamedResponse
    {
        $brand = $workspace->brand();
        $this->authorize('viewAny', [Post::class, $brand]);

        $bytes = $pdf($build($build->kitFor($brand), $brand));

        return ResponseFactory::streamDownload(
            fn () => print ($bytes),
            GenerateMediaKitPdfAction::filename($brand),
            ['Content-Type' => 'application/pdf'],
        );
    }
}
