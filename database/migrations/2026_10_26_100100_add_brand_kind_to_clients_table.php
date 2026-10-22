<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step B-2.1 / B-2.3 — the business signup and onboarding record a few things on the primary brand:
 * "I'm a business / a creator" (`brand_kind`) and the brand's location (city/country), which the
 * local & seasonal idea engine (B-6.1) will use. Both are nullable: every existing (agency) client
 * and the super-admin onboarding path leave them unset; only the business flow fills them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            if (! Schema::hasColumn('clients', 'brand_kind')) {
                $table->string('brand_kind')->nullable()->after('is_primary');
            }

            if (! Schema::hasColumn('clients', 'location')) {
                $table->string('location')->nullable()->after('industry');
            }
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['brand_kind', 'location']);
        });
    }
};
