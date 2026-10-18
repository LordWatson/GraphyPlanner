<?php

namespace App\Exceptions\Posts;

use App\Enums\PostStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use InvalidArgumentException;

/**
 * Step I-1.4 — thrown by `TransitionPostStatusAction` when the move isn't legal from the post's
 * *locked* status. Form Requests already reject illegal moves up front, so reaching this means
 * another request changed the post in the meantime (e.g. the client approved through the review
 * link while a teammate requested changes in the portal). Renders as a 409 for JSON callers and
 * as a validation-style redirect back (with a toast) for Inertia pages.
 */
class InvalidPostTransitionException extends InvalidArgumentException
{
    public function __construct(
        public readonly int $postId,
        public readonly PostStatus $from,
        public readonly PostStatus $to,
    ) {
        parent::__construct("Cannot transition post {$postId} from {$from->value} to {$to->value}.");
    }

    public function render(Request $request): JsonResponse|RedirectResponse
    {
        $message = __('This post changed while you were working on it — it is now :status.', [
            'status' => strtolower($this->from->label()),
        ]);

        if ($request->expectsJson()) {
            return response()->json(['message' => $message], 409);
        }

        Inertia::flash('toast', ['type' => 'error', 'message' => $message]);

        return back()->withErrors(['to' => $message]);
    }
}
