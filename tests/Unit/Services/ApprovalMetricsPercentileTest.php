<?php

namespace Tests\Unit\Services;

use App\Services\Analytics\ApprovalMetrics;
use PHPUnit\Framework\TestCase;

/**
 * Step 17.3 — turnaround medians/p90s use linear interpolation (spreadsheet `PERCENTILE.INC`).
 */
class ApprovalMetricsPercentileTest extends TestCase
{
    public function test_percentiles(): void
    {
        $this->assertNull(ApprovalMetrics::percentile([], 50));
        $this->assertSame(7.0, ApprovalMetrics::percentile([7.0], 90));
        $this->assertSame(2.5, ApprovalMetrics::percentile([4.0, 1.0, 3.0, 2.0], 50));
        $this->assertSame(24.0, ApprovalMetrics::percentile([6.0, 24.0, 30.0], 50));
        $this->assertSame(28.8, ApprovalMetrics::percentile([30.0, 6.0, 24.0], 90));
        $this->assertSame(9.1, ApprovalMetrics::percentile([1.0, 2.0, 3.0, 4.0, 5.0, 6.0, 7.0, 8.0, 9.0, 10.0], 90));
    }
}
