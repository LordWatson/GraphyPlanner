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
            ['org_id' => $organization->id, 'name' => 'Acme Corp'],
            [
                'legal_name' => 'Acme Corporation Ltd',
                'website' => 'https://acme.test',
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
                'approval_email' => 'approvals@acme.test',
            ]
        );

        Client::updateOrCreate(
            ['org_id' => $organization->id, 'name' => 'Nimbus Studios'],
            [
                'legal_name' => 'Nimbus Studios Inc',
                'website' => 'https://nimbusstudios.test',
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
                'approval_email' => 'contact@nimbusstudios.test',
            ]
        );

        Client::updateOrCreate(
            ['org_id' => $organization->id, 'name' => 'Blue Harbor Cafe'],
            [
                'legal_name' => null,
                'website' => 'https://blueharborcafe.test',
                'industry' => 'Food & Beverage',
                'countries' => ['US'],
                'status' => ClientStatus::Offboarding,
                'owner_user_id' => $owner?->id,
                'start_date' => now()->subMonths(18)->toDateString(),
                'retainer_amount' => 800,
                'billing_cycle' => BillingCycle::ProjectBased,
                'tags' => ['local', 'hospitality'],
                'default_language' => 'en',
                'notes_internal' => 'Wrapping down engagement this quarter.',
                'approval_email' => 'owner@blueharborcafe.test',
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
