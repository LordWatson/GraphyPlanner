<?php

namespace App\Services\Analytics;

use App\Models\AiUsageLog;
use App\Support\Analytics\AnalyticsRange;
use App\Support\Analytics\AnalyticsScope;
use Illuminate\Database\Eloquent\Builder;

/**
 * Step 17.1 — AI vendor usage from `ai_usage_logs` (one row per vendor call, success or failure,
 * with its USD cost estimate). Shared by the platform console and organization analytics.
 */
class AiUsageMetrics
{
    /**
     * @return Builder<AiUsageLog>
     */
    public function calls(AnalyticsScope $scope, AnalyticsRange $range): Builder
    {
        return $scope->applyToClientOwned(AiUsageLog::query())->whereBetween('created_at', $range->utcBounds());
    }

    /**
     * @return array{generated: int, failed: int, cost_estimate: float}
     */
    public function summary(AnalyticsScope $scope, AnalyticsRange $range): array
    {
        return [
            'generated' => $this->calls($scope, $range)->where('succeeded', true)->count(),
            'failed' => $this->calls($scope, $range)->where('succeeded', false)->count(),
            'cost_estimate' => round((float) $this->calls($scope, $range)->sum('cost_estimate'), 2),
        ];
    }

    /**
     * Succeeded/failed call counts per logged `method` (e.g. `image.generate`, `image.edit`).
     *
     * @return array<string, array{succeeded: int, failed: int}>
     */
    public function byMethod(AnalyticsScope $scope, AnalyticsRange $range): array
    {
        $rows = $this->calls($scope, $range)
            ->toBase()
            ->selectRaw('method, succeeded, COUNT(*) as aggregate')
            ->groupBy('method', 'succeeded')
            ->get();

        $methods = [];

        foreach ($rows as $row) {
            $method = (string) $row->method;
            $methods[$method] ??= ['succeeded' => 0, 'failed' => 0];
            $methods[$method][(bool) $row->succeeded ? 'succeeded' : 'failed'] += (int) $row->aggregate;
        }

        ksort($methods);

        return $methods;
    }
}
