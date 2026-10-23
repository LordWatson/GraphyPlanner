<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step B-2.2 — when a business workspace finished (or skipped) its guided setup. Null means "still
 * onboarding": such a business org lands on `/welcome-setup` after login. The checklist itself is
 * computed from real data by `OnboardingProgress`; this column only records the "done / skipped"
 * decision so an owner isn't nagged forever. Agency orgs leave it null and never see the flow.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('organizations', 'onboarding_completed_at')) {
            return;
        }

        Schema::table('organizations', function (Blueprint $table) {
            $table->timestamp('onboarding_completed_at')->nullable()->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('onboarding_completed_at');
        });
    }
};
