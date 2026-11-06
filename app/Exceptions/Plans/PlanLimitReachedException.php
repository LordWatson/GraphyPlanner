<?php

namespace App\Exceptions\Plans;

use App\Contracts\Entitlements;
use App\Enums\Feature;
use App\Support\Plans\Plan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use RuntimeException;

/**
 * Step CG-2.2 / B-9.1 — raised by {@see Entitlements::ensure()} when an org tries to
 * exceed its plan. It renders itself as a *friendly upgrade prompt*, never a 403: an Inertia request
 * is redirected back with a toast and an `upgrade_prompt` flash the plan page/EmptyState can key off,
 * and an API/JSON request gets a 402 with the same shape.
 */
class PlanLimitReachedException extends RuntimeException
{
    public function __construct(
        public readonly Feature $feature,
        public readonly Plan $currentPlan,
        public readonly ?int $limit,
        public readonly int $current,
        public readonly ?Plan $upgradeTo = null,
        string $message = '',
    ) {
        parent::__construct($message !== '' ? $message : $this->defaultMessage());
    }

    private function defaultMessage(): string
    {
        $upgrade = $this->upgradeTo?->name();

        if ($this->feature->isGate()) {
            return $upgrade !== null
                ? sprintf('%s is on the %s plan. Upgrade to switch it on.', $this->feature->label(), $upgrade)
                : sprintf('%s isn’t included on your plan.', $this->feature->label());
        }

        $label = strtolower($this->feature->label());

        return $upgrade !== null
            ? sprintf('You’ve reached your plan’s %s. Upgrade to %s for more.', $label, $upgrade)
            : sprintf('You’ve reached your plan’s %s.', $label);
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'feature' => $this->feature->value,
            'feature_label' => $this->feature->label(),
            'limit' => $this->limit,
            'current' => $this->current,
            'current_plan' => $this->currentPlan->key,
            'upgrade_to' => $this->upgradeTo?->key,
            'upgrade_to_name' => $this->upgradeTo?->name(),
            'message' => $this->getMessage(),
        ];
    }

    public function render(Request $request): RedirectResponse|JsonResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $this->getMessage(), 'upgrade' => $this->toPayload()], 402);
        }

        Inertia::flash('toast', ['type' => 'info', 'message' => $this->getMessage()]);

        return back()->with('upgrade_prompt', $this->toPayload());
    }
}
