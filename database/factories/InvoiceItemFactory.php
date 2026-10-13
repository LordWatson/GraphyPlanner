<?php

namespace Database\Factories;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InvoiceItem>
 */
class InvoiceItemFactory extends Factory
{
    /**
     * Configure the model factory.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (InvoiceItem $item) {
            if (! $item->org_id && $item->invoice_id) {
                $item->org_id = Invoice::find($item->invoice_id)?->org_id;
            }
        });
    }

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'invoice_id' => Invoice::factory(),
            'description' => fake()->sentence(),
            'is_billable' => true,
            'price' => fake()->randomFloat(2, 10, 500),
            'quantity' => 1,
            'sort_order' => 0,
        ];
    }

    /**
     * Indicate that the item is not billable (always a zero line total).
     */
    public function notBillable(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_billable' => false,
        ]);
    }
}
