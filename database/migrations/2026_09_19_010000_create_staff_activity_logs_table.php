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
        Schema::create('staff_activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('org_id')->constrained('organizations')->cascadeOnDelete();

            // The staff member the log entry is about. Nullable + set-null-on-delete so history
            // survives even if the member record is later removed.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            // Who performed the action (an Owner). Nullable for the same reason as above.
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('action');
            $table->string('from_role')->nullable();
            $table->string('to_role')->nullable();
            $table->text('note')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('staff_activity_logs');
    }
};
