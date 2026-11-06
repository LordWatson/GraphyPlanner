<?php

namespace App\Http\Controllers;

use App\Actions\Business\BuildBioPageAction;
use App\Actions\Business\RecordBioEventAction;
use App\Models\BioBlock;
use App\Models\BioPage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Step CG-3.1 / CG-3.3 — the public link in bio at `/@{handle}`, and the counted click-through at
 * `/@{handle}/go/{block}`. No auth: the handle is the whole address. An unpublished page, or one
 * whose brand has been deleted, 404s — exactly like a revoked media-kit link.
 */
class BioPageController extends Controller
{
    public function show(Request $request, string $handle, BuildBioPageAction $build, RecordBioEventAction $record): Response
    {
        $page = $this->resolve($handle);

        $record($request, $page);

        return Inertia::render('public/bio', ['page' => $build($page)]);
    }

    /**
     * Count the click, then send the visitor on. The destination is resolved server-side
     * ({@see BuildBioPageAction::destination()}) so the public page never carries the real URL and
     * the count can't be skipped.
     */
    public function click(Request $request, string $handle, int $block, BuildBioPageAction $build, RecordBioEventAction $record): RedirectResponse
    {
        $page = $this->resolve($handle);

        $found = BioBlock::query()
            ->where('bio_page_id', $page->id)
            ->where('is_visible', true)
            ->find($block);

        abort_if($found === null, 404);

        $destination = $build->destination($page, $found);

        abort_if($destination === null, 404);

        $record($request, $page, $found->id);

        return redirect()->away($destination);
    }

    private function resolve(string $handle): BioPage
    {
        $page = BioPage::query()->where('handle', strtolower($handle))->first();

        abort_if($page === null || ! $page->isPublished() || $page->client === null, 404);

        return $page;
    }
}
