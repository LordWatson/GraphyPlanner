<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step B-3.3 — whether a business workspace makes posts pass through an internal review step before
 * they can be approved/scheduled. Default `false`: a solo owner self-approves (`BusinessPostWorkflow`
 * allows `draft → approved`). An agency ignores this flag entirely — its lifecycle always has a
 * review stage — so existing orgs are unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('organizations', 'require_post_review')) {
            return;
        }

        Schema::table('organizations', function (Blueprint $table) {
            $table->boolean('require_post_review')->default(false)->after('onboarding_completed_at');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('require_post_review');
        });
    }
};
