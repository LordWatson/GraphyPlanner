<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step I-16.1 / M-1.1 — the correlation id of the request/job that sent a target to the vendor,
 * so the vendor's webhook (a separate request with its own id) can be joined back to it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('post_targets', function (Blueprint $table) {
            $table->string('publish_correlation_id', 64)->nullable()->after('publish_requested_at');
        });
    }

    public function down(): void
    {
        Schema::table('post_targets', function (Blueprint $table) {
            $table->dropColumn('publish_correlation_id');
        });
    }
};
