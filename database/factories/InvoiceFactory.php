<?php

namespace Database\Factories;

use App\Enums\InvoiceStatus;
use App\Models\Client;
use App\Models\Invoice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    /**
     * Configure the model factory.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (Invoice $invoice) {
            if (! $invoice->org_id && $invoice->client_id) {
                $invoice->org_id = Client::find($invoice->client_id)?->org_id;
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
            'client_id' => Client::factory(),
            'invoice_number' => 'INV-'.fake()->unique()->numerify('######'),
            'status' => InvoiceStatus::Draft,
            'amount' => fake()->randomFloat(2, 100, 5000),
            'currency' => 'USD',
            'issue_date' => fake()->dateTimeBetween('-6 months', 'now')->format('Y-m-d'),
            'due_date' => fake()->dateTimeBetween('now', '+1 month')->format('Y-m-d'),
            'description' => fake()->sentence(),
            'sent_at' => null,
            'paid_at' => null,
        ];
    }

    /**
     * Indicate that the invoice has been sent.
     */
    public function sent(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => InvoiceStatus::Sent,
            'sent_at' => fake()->dateTimeBetween($attributes['issue_date'] ?? '-1 month', 'now'),
        ]);
    }

    /**
     * Indicate that the invoice has been paid.
     */
    public function paid(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => InvoiceStatus::Paid,
            'sent_at' => $attributes['sent_at'] ?? fake()->dateTimeBetween($attributes['issue_date'] ?? '-2 months', '-1 week'),
            'paid_at' => fake()->dateTimeBetween('-1 week', 'now'),
        ]);
    }
}
