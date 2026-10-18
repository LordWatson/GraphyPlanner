<?php

namespace App\Exceptions\Campaigns;

use App\Enums\CampaignStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use InvalidArgumentException;

/**
 * Step C-0.3 — thrown by `TransitionCampaignStatusAction` when a move isn't legal from the
 * campaign's *locked* status. Renders as a 422 for JSON callers and a validation-style redirect
 * back (with a toast) for Inertia pages, so nothing is written.
 */
class InvalidCampaignTransitionException extends InvalidArgumentException
{
    public function __construct(
        public readonly int $campaignId,
        public readonly CampaignStatus $from,
        public readonly CampaignStatus $to,
    ) {
        parent::__construct("Cannot transition campaign {$campaignId} from {$from->value} to {$to->value}.");
    }

    public function render(Request $request): JsonResponse|RedirectResponse
    {
        $message = __('A :from campaign can’t move to :to.', [
            'from' => strtolower($this->from->label()),
            'to' => strtolower($this->to->label()),
        ]);

        if ($request->expectsJson()) {
            return response()->json(['message' => $message, 'errors' => ['to' => [$message]]], 422);
        }

        Inertia::flash('toast', ['type' => 'error', 'message' => $message]);

        return back()->withErrors(['to' => $message]);
    }
}
