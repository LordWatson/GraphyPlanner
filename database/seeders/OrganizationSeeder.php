<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class OrganizationSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $organization = Organization::updateOrCreate(
            ['slug' => 'graphy-agency'],
            [
                'name' => 'Graphy Agency',
                'default_timezone' => 'UTC',
            ]
        );

        User::query()
            ->whereIn('email', ['test@example.com', 'alexander.watson.work@gmail.com'])
            ->update([
                'org_id' => $organization->id,
                'role' => Role::Owner,
            ]);

        User::updateOrCreate(
            ['email' => 'strategist@graphy.test'],
            [
                'name' => 'Sam Strategist',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
                'org_id' => $organization->id,
                'role' => Role::Strategist,
            ]
        );

        User::updateOrCreate(
            ['email' => 'designer@graphy.test'],
            [
                'name' => 'Dana Designer',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
                'org_id' => $organization->id,
                'role' => Role::Designer,
            ]
        );

        User::updateOrCreate(
            ['email' => 'viewer@graphy.test'],
            [
                'name' => 'Vic Viewer',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
                'org_id' => $organization->id,
                'role' => Role::Viewer,
            ]
        );
    }
}
