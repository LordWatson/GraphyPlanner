<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step W-1.3 — numbers that must never be messaged, whatever anybody's preferences say.
 *
 * This table outranks every opt-in. A number lands here when its owner sends STOP, or when a send
 * comes back permanently undeliverable, and nothing is sent to it again until a START removes the
 * row. `org_id` is nullable so a suppression can be global — if somebody opts out, opting them
 * back in for a different agency on the same platform number is not ours to assume.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_suppressions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('org_id')->nullable()->constrained('organizations')->cascadeOnDelete();
            $table->string('phone', 32);
            $table->string('reason', 32);
            $table->timestamps();

            // The check runs on every send, so it has to be one index hit.
            $table->index(['phone', 'org_id']);
            $table->unique(['phone', 'org_id']);
            // `IndexCoverageTest`: a foreign key leads an index of its own, so a cascading delete
            // does not scan the table.
            $table->index('org_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_suppressions');
    }
};
