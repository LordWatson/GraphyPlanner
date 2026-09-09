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
            // A free-form message the strategist/designer writes for the client, shown on the
            // review portal and included in the "New content ready for your review" email
            // whenever the post is sent for client review.
            $table->text('review_message')->nullable()->after('master_caption');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropColumn('review_message');
        });
    }
};
