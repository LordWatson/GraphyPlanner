<?php

namespace App\Http\Controllers;

use App\Actions\SubmitProductFeedbackAction;
use App\Http\Requests\StoreProductFeedbackRequest;
use Illuminate\Http\RedirectResponse;

/**
 * Step M-8.2 — the staff "Send feedback" panel. One thin endpoint: validate, store, redirect back.
 * The success toast is shown client-side (the dialog closes on success).
 */
class ProductFeedbackController extends Controller
{
    public function store(StoreProductFeedbackRequest $request, SubmitProductFeedbackAction $submit): RedirectResponse
    {
        $submit(
            $request->user(),
            $request->string('message')->toString(),
            $request->filled('page') ? $request->string('page')->toString() : null,
        );

        return back();
    }
}
