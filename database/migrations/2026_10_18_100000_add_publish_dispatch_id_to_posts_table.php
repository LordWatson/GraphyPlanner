<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            // Step I-1.2: the id of the one publish job allowed to act on this post. Written on
            // every move into `scheduled`, cleared on the way out — a queued `PublishPostJob`
            // whose id no longer matches is stale and no-ops.
            $table->uuid('publish_dispatch_id')->nullable()->after('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropColumn('publish_dispatch_id');
        });
    }
};
