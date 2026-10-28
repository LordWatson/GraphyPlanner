<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase CG-2 (Step CG-2.6 / B-9.4) — an audit trail of super-admin plan actions: plan overrides,
 * temporary AI credit grants, and per-org billing-switch overrides. Records who did what, when.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('org_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action');
            $table->string('from_plan')->nullable();
            $table->string('to_plan')->nullable();
            $table->string('note')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['org_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_audit_logs');
    }
};
