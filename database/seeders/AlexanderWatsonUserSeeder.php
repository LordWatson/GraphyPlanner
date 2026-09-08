<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AlexanderWatsonUserSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'alexander.watson.work@gmail.com'],
            [
                'name' => 'Alexander Watson',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]
        );

        User::updateOrCreate(
            ['email' => 'rseklani@gmail.com'],
            [
                'name' => 'Rimma Seklani',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]
        );
    }
}
