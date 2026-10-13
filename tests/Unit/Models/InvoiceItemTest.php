<?php

namespace Tests\Unit\Models;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceItemTest extends TestCase
{
    use RefreshDatabase;

    public function test_line_total_multiplies_price_by_quantity_for_billable_item(): void
    {
        $invoice = Invoice::factory()->create();

        $item = InvoiceItem::factory()->for($invoice)->create([
            'is_billable' => true,
            'price' => 50,
            'quantity' => 3,
        ]);

        $this->assertSame(150.0, $item->lineTotal());
    }

    public function test_line_total_treats_missing_price_as_zero_for_billable_item(): void
    {
        $invoice = Invoice::factory()->create();

        $item = InvoiceItem::factory()->for($invoice)->create([
            'is_billable' => true,
            'price' => null,
            'quantity' => 2,
        ]);

        $this->assertSame(0.0, $item->lineTotal());
    }

    public function test_line_total_is_always_zero_for_non_billable_item_regardless_of_price(): void
    {
        $invoice = Invoice::factory()->create();

        $item = InvoiceItem::factory()->for($invoice)->create([
            'is_billable' => false,
            'price' => 999,
            'quantity' => 5,
        ]);

        $this->assertSame(0.0, $item->lineTotal());
    }
}
