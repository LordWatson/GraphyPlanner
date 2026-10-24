<?php

namespace App\Http\Controllers;

use App\Actions\Inbox\SaveSavedReplyAction;
use App\Http\Requests\Inbox\SaveSavedReplyRequest;
use App\Models\SavedReply;
use Illuminate\Http\RedirectResponse;

/**
 * Step M-9.2 — manage the org's canned inbox replies. Thin: validate via the Form Request, delegate
 * to the Action, redirect back to the inbox. Owner/Strategist/Designer only (SavedReplyPolicy).
 */
class SavedReplyController extends Controller
{
    public function store(SaveSavedReplyRequest $request, SaveSavedReplyAction $action): RedirectResponse
    {
        $this->authorize('create', SavedReply::class);

        $action($request->user()->organization, $request->user(), $request->payload());

        return back()->with('status', 'Saved reply added.');
    }

    public function update(SaveSavedReplyRequest $request, SavedReply $savedReply, SaveSavedReplyAction $action): RedirectResponse
    {
        $this->authorize('update', $savedReply);

        $action($request->user()->organization, $request->user(), $request->payload(), $savedReply);

        return back()->with('status', 'Saved reply updated.');
    }

    public function destroy(SavedReply $savedReply): RedirectResponse
    {
        $this->authorize('delete', $savedReply);

        $savedReply->delete();

        return back()->with('status', 'Saved reply removed.');
    }
}
