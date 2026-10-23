<?php

namespace App\Http\Controllers;

use App\Models\SavedPostView;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Step M-5.1 — a staff member's personal, named content-hub filter views. Create from the hub's
 * current filters and delete; there is no edit (re-save a view with the same name to replace it).
 * Every view is pinned to its owner by `SavedPostViewPolicy`.
 */
class SavedPostViewController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', SavedPostView::class);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'filters' => ['present', 'array'],
        ]);

        $user = $request->user();

        SavedPostView::updateOrCreate(
            ['user_id' => $user->id, 'name' => $validated['name']],
            ['org_id' => $user->org_id, 'filters' => $validated['filters']],
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('View saved.')]);

        return back();
    }

    public function destroy(SavedPostView $savedView): RedirectResponse
    {
        $this->authorize('delete', $savedView);

        $savedView->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('View removed.')]);

        return back();
    }
}
