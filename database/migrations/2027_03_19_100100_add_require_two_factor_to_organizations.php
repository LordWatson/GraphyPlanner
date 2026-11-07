<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Every member of this organisation must have two-factor authentication" — the switch a security
 * questionnaire asks for and which Fortify's per-user 2FA cannot answer on its own.
 *
 * Defaults to **false**: turning it on locks out every member who has not enrolled yet, so it has
 * to be a deliberate act by an owner who already holds a second factor themselves (enforced in
 * `OrganizationSettingsUpdateRequest`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->boolean('require_two_factor')->default(false)->after('restrict_staff_to_assigned_clients');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('require_two_factor');
        });
    }
};
