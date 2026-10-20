<?php

namespace App\Exceptions\Grids;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use RuntimeException;

/**
 * Step 18.4 / 18.8 — a write based on a grid `version` that's no longer current (someone else
 * reordered the grid, or the client is deciding on a grid that changed while they looked).
 * Nothing is changed. Renders as a 409 for JSON callers and as a redirect back with a toast for
 * Inertia pages, which reloads the latest grid.
 */
class StaleGridPlanException extends RuntimeException
{
    public function __construct(
        public readonly int $gridPlanId,
        public readonly int $expectedVersion,
        public readonly int $currentVersion,
        string $message = 'Someone else changed this grid. Refresh to see it.',
    ) {
        parent::__construct($message);
    }

    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $this->getMessage(), 'version' => $this->currentVersion], 409);
        }

        Inertia::flash('toast', ['type' => 'error', 'message' => $this->getMessage()]);

        return back()->withErrors(['version' => $this->getMessage()]);
    }
}
