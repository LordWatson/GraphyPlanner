<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step M-5.1 — saved filter views for the content hub (`/content`). Each view belongs to one user
 * and stores the filter query they named, so a strategist can keep "This week, waiting on client"
 * one click away. Per-user, never shared, so no org-wide visibility concerns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_post_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('org_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            // The content-hub filter query (client, campaign, status, platform, dates, has_media…).
            $table->json('filters');
            $table->timestamps();

            // IndexCoverageTest: every foreign-key column must lead an index.
            $table->index('org_id');
            // One name per user; the leading column also indexes the user_id foreign key.
            $table->unique(['user_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_post_views');
    }
};
