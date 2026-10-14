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
        Schema::create('daily_briefs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('org_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->date('brief_date');
            $table->json('trends')->nullable();
            $table->json('ideas')->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();

            // Step 2.5: one brief per client per day — reruns of the nightly job never duplicate.
            $table->unique(['client_id', 'brief_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('daily_briefs');
    }
};
