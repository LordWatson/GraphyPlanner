<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase CG-2 (Step CG-2.6) — a tiny key/value store for platform-wide super-admin settings. First
 * use: the global Stripe billing switch (`billing.enabled`). Read through
 * App\Services\Platform\PlatformSettings (cached), written only from the super-admin console.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->json('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_settings');
    }
};
