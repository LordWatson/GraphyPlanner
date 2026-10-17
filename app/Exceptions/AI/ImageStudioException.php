<?php

namespace App\Exceptions\AI;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * A request the Image Studio refuses up front (quota, rate limit) with one friendly line. Renders
 * itself, so controllers stay thin: Inertia requests get the message back as the `imageStudio`
 * validation error, JSON callers a 429.
 */
abstract class ImageStudioException extends RuntimeException
{
    public function render(Request $request): RedirectResponse|JsonResponse
    {
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['message' => $this->getMessage()], 429);
        }

        return back()->withErrors(['imageStudio' => $this->getMessage()]);
    }

    /**
     * Expected, user-facing refusals: never reported as application errors.
     */
    public function report(): bool
    {
        return true;
    }
}
