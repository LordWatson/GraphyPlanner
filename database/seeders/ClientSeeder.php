<?php

namespace Database\Seeders;

use App\Enums\BillingCycle;
use App\Enums\ClientStatus;
use App\Enums\Role;
use App\Models\Client;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class ClientSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $organization = Organization::firstWhere('slug', 'graphy-agency');

        if (! $organization) {
            return;
        }

        $owner = User::firstWhere('email', 'test@example.com');

        $acme = Client::updateOrCreate(
            ['org_id' => $organization->id, 'name' => 'Recommendo'],
            [
                'legal_name' => 'Recommendo',
                'website' => 'https://recommendo.test',
                'industry' => 'Retail',
                'countries' => ['US', 'CA'],
                'status' => ClientStatus::Active,
                'owner_user_id' => $owner?->id,
                'start_date' => now()->subMonths(6)->toDateString(),
                'retainer_amount' => 2500,
                'billing_cycle' => BillingCycle::Monthly,
                'tags' => ['retail', 'priority'],
                'default_language' => 'en',
                'notes_internal' => 'Key account, weekly check-ins.',
                'approval_email' => 'approvals@recommendo.test',
            ]
        );

        Client::updateOrCreate(
            ['org_id' => $organization->id, 'name' => 'Frzn'],
            [
                'legal_name' => 'Frzn',
                'website' => 'https://frzn.test',
                'industry' => 'Entertainment',
                'countries' => ['GB'],
                'status' => ClientStatus::Paused,
                'owner_user_id' => $owner?->id,
                'start_date' => now()->subYear()->toDateString(),
                'retainer_amount' => 1200,
                'billing_cycle' => BillingCycle::Quarterly,
                'tags' => ['media'],
                'default_language' => 'en',
                'notes_internal' => 'Paused pending contract renewal.',
                'approval_email' => 'contact@frzn.test',
            ]
        );

        User::updateOrCreate(
            ['email' => 'reviewer@acme.test'],
            [
                'name' => 'Rae Reviewer',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
                'org_id' => $organization->id,
                'role' => Role::ClientReviewer,
                'client_id' => $acme->id,
            ]
        );
    }
}
