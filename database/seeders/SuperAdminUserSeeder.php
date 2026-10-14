<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Standalone seeder for the platform super admin account.
 *
 * Run on its own (does not depend on DatabaseSeeder/OrganizationSeeder):
 *   php artisan db:seed --class=Database\\Seeders\\SuperAdminUserSeeder
 */
class SuperAdminUserSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'alexander.watson.dev@gmail.com'],
            [
                'name' => 'Alexander Watson',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
                'role' => Role::SuperAdmin,
                'org_id' => null,
            ]
        );
    }
}
