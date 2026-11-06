<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step CG-5.1 (B-11.1) — paid-partnership disclosure on a post.
 *
 * `is_paid_partnership` is the owner's declaration; the pre-schedule checklist then **blocks**
 * scheduling until every channel's published caption actually discloses
 * (`App\Support\Posts\PartnershipDisclosure`). `partner_name`/`partner_handle` are who it is with —
 * shown to a sponsor reviewing a share preview (B-3.4), and carried onto the brand-deals tracker
 * (CG-5.2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->boolean('is_paid_partnership')->default(false)->after('first_comment');
            $table->string('partner_name')->nullable()->after('is_paid_partnership');
            $table->string('partner_handle', 64)->nullable()->after('partner_name');
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropColumn(['is_paid_partnership', 'partner_name', 'partner_handle']);
        });
    }
};
