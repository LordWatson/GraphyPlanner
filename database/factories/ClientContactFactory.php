<?php

namespace Database\Factories;

use App\Enums\ClientContactRole;
use App\Models\Client;
use App\Models\ClientContact;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClientContact>
 */
class ClientContactFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Create the client up front so the contact's `org_id` always matches its client. The
        // `forClient()` state overrides both when attaching to an existing client.
        $client = Client::factory()->create();

        return [
            'client_id' => $client->id,
            'org_id' => $client->org_id,
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => null,
            'role' => ClientContactRole::Approver,
            'receives_review_emails' => true,
            'receives_invoices' => false,
        ];
    }

    public function approver(): static
    {
        return $this->state(['role' => ClientContactRole::Approver, 'receives_review_emails' => true]);
    }

    public function billing(): static
    {
        return $this->state(['role' => ClientContactRole::Billing, 'receives_invoices' => true, 'receives_review_emails' => false]);
    }

    /**
     * Attach to an existing client, inheriting its organization.
     */
    public function forClient(Client $client): static
    {
        return $this->state(['client_id' => $client->id, 'org_id' => $client->org_id]);
    }
}
