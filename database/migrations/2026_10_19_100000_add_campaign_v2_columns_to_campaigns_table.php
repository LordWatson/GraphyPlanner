<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step C-0.2 — campaign data model v2: an accountable owner, an objective, an identity colour,
 * intended platforms, the campaign hashtag set / default link / UTM name (C-4.2), and a short code
 * unique per client (`SPR26`) shown on chips and invoices.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->foreignId('owner_id')->nullable()->after('client_id')->constrained('users')->nullOnDelete();
            $table->string('code', 12)->nullable()->after('name');
            $table->string('objective')->nullable()->after('status');
            $table->string('colour')->nullable()->after('objective');
            $table->json('platforms')->nullable()->after('colour');
            $table->json('hashtags')->nullable()->after('notes');
            $table->string('default_link_url', 2048)->nullable()->after('hashtags');
            $table->string('utm_campaign', 100)->nullable()->after('default_link_url');

            $table->unique(['client_id', 'code']);
            $table->index(['client_id', 'status']);
            $table->index(['org_id', 'start_date']);
            $table->index('owner_id');
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropIndex(['org_id', 'start_date']);
            $table->dropIndex(['client_id', 'status']);
            $table->dropUnique(['client_id', 'code']);
            $table->dropConstrainedForeignId('owner_id');
            $table->dropColumn(['code', 'objective', 'colour', 'platforms', 'hashtags', 'default_link_url', 'utm_campaign']);
        });
    }
};
