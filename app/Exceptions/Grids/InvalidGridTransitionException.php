<?php

namespace App\Exceptions\Grids;

use App\Enums\GridPlanStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use InvalidArgumentException;

/**
 * Step 18.7 — thrown by `TransitionGridPlanStatusAction` when the move isn't legal from the plan's
 * locked status (another request moved it in the meantime). 409 for JSON, toast + redirect back
 * for Inertia pages — same as `InvalidPostTransitionException`.
 */
class InvalidGridTransitionException extends InvalidArgumentException
{
    public function __construct(
        public readonly int $gridPlanId,
        public readonly GridPlanStatus $from,
        public readonly GridPlanStatus $to,
    ) {
        parent::__construct("Cannot move grid plan {$gridPlanId} from {$from->value} to {$to->value}.");
    }

    public function render(Request $request): JsonResponse|RedirectResponse
    {
        $message = __('This grid changed while you were working on it. It is now :status.', [
            'status' => strtolower($this->from->label()),
        ]);

        if ($request->expectsJson()) {
            return response()->json(['message' => $message], 409);
        }

        Inertia::flash('toast', ['type' => 'error', 'message' => $message]);

        return back()->withErrors(['to' => $message]);
    }
}
