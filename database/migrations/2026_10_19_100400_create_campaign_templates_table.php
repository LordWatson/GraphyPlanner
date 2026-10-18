<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step C-3.4 — reusable, org-scoped campaign shapes: a brief skeleton, phases as day offsets and
 * deliverables as day offsets from the start date. Never tied to a client.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('org_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->string('objective')->nullable();
            $table->unsignedSmallInteger('duration_days')->default(28);
            $table->json('brief')->nullable();
            $table->json('phases')->nullable();
            $table->json('deliverables')->nullable();
            $table->boolean('is_starter')->default(false);
            $table->timestamps();

            $table->index(['org_id', 'name']);
            $table->index('created_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_templates');
    }
};
