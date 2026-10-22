<?php

namespace Tests\Unit\Support\Invoices;

use App\Support\Invoices\InvoiceTotals;
use PHPUnit\Framework\TestCase;

class InvoiceTotalsTest extends TestCase
{
    public function test_a_line_with_no_rate_adds_no_tax_so_total_equals_subtotal(): void
    {
        $totals = InvoiceTotals::fromLines([
            ['net' => 100, 'rate' => null, 'name' => null],
            ['net' => 50],
        ]);

        $this->assertSame(150.0, $totals->subtotal);
        $this->assertSame(0.0, $totals->taxTotal);
        $this->assertSame(150.0, $totals->total);
        $this->assertSame([], $totals->breakdown);
    }

    public function test_it_taxes_a_single_rate(): void
    {
        $totals = InvoiceTotals::fromLines([
            ['net' => 200, 'rate' => 20, 'name' => 'VAT 20%'],
        ]);

        $this->assertSame(200.0, $totals->subtotal);
        $this->assertSame(40.0, $totals->taxTotal);
        $this->assertSame(240.0, $totals->total);
        $this->assertCount(1, $totals->breakdown);
        $this->assertSame('VAT 20%', $totals->breakdown[0]['name']);
        $this->assertSame(40.0, $totals->breakdown[0]['tax']);
    }

    public function test_it_groups_mixed_rates_and_merges_same_rate_lines(): void
    {
        $totals = InvoiceTotals::fromLines([
            ['net' => 100, 'rate' => 20, 'name' => 'VAT 20%'],
            ['net' => 50, 'rate' => 20, 'name' => 'VAT 20%'],
            ['net' => 80, 'rate' => 5, 'name' => 'UAE VAT 5%'],
            ['net' => 30, 'rate' => null, 'name' => null],
        ]);

        // Subtotal is every net; tax is 20% of 150 + 5% of 80.
        $this->assertSame(260.0, $totals->subtotal);
        $this->assertSame(34.0, $totals->taxTotal);
        $this->assertSame(294.0, $totals->total);

        // The two 20% lines merge into one breakdown row.
        $this->assertCount(2, $totals->breakdown);
        $twenty = collect($totals->breakdown)->firstWhere('name', 'VAT 20%');
        $this->assertSame(150.0, $twenty['net']);
        $this->assertSame(30.0, $twenty['tax']);
    }

    public function test_to_invoice_columns_nulls_an_empty_breakdown(): void
    {
        $columns = InvoiceTotals::fromLines([['net' => 100]])->toInvoiceColumns();

        $this->assertNull($columns['tax_breakdown']);
        $this->assertSame(100.0, $columns['amount']);
        $this->assertSame(100.0, $columns['subtotal']);
        $this->assertSame(0.0, $columns['tax_total']);
    }
}
