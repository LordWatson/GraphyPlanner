<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step M-8.1 — the design-partner flag. A super admin sets it by hand on the (up to five) orgs
     * invited onto the design-partner offer (free for 3 months, then 50% off for 12). It drives the
     * partner usage view now, and M-10 auto-applies the right coupon to these orgs later. Indexed
     * because the partner view filters by it.
     */
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->boolean('is_design_partner')->default(false)->after('type');
            $table->index('is_design_partner');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropIndex(['is_design_partner']);
            $table->dropColumn('is_design_partner');
        });
    }
};
