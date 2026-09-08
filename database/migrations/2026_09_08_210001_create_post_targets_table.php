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
        Schema::create('post_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained('posts')->cascadeOnDelete();
            $table->foreignId('social_account_id')->constrained('social_accounts')->cascadeOnDelete();

            // Independent per-target schedule: two targets on accounts in different timezones
            // must keep their own local date/time — never collapsed onto one shared UTC value
            // unless the user explicitly opts into "same instant" (spec §4.6).
            $table->date('scheduled_local_date')->nullable();
            $table->time('scheduled_local_time')->nullable();
            $table->timestamp('scheduled_at_utc')->nullable();

            $table->timestamps();

            $table->unique(['post_id', 'social_account_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('post_targets');
    }
};
