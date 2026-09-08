<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('social_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('org_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->string('platform');
            $table->string('handle');
            $table->string('display_name')->nullable();
            $table->string('timezone');
            $table->string('language')->nullable();
            $table->string('country')->nullable();
            $table->string('default_location')->nullable();
            $table->json('posting_windows')->nullable();
            $table->text('persona_override')->nullable();
            $table->string('connection_status')->default('not_connected');

            // Publisher/vendor fields — present now, wired up in Phase 1. Do not use before then.
            $table->string('provider')->nullable();
            $table->string('external_profile_id')->nullable();
            $table->string('external_account_id')->nullable();
            $table->timestamp('connected_at')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('social_accounts');
    }
};
