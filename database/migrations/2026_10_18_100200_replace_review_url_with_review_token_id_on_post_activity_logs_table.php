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
        Schema::table('post_activity_logs', function (Blueprint $table) {
            // Step I-1.6: the raw review link (plain token) used to be stored here, undoing the
            // point of hashing `ReviewToken` at rest. Only the token's id is kept now; "Resend
            // review email" mints a fresh token instead of replaying a stored URL.
            $table->foreignId('review_token_id')->nullable()->after('note')
                ->constrained('review_tokens')->nullOnDelete();
        });

        Schema::table('post_activity_logs', function (Blueprint $table) {
            $table->dropColumn('review_url');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('post_activity_logs', function (Blueprint $table) {
            $table->text('review_url')->nullable()->after('note');
        });

        Schema::table('post_activity_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('review_token_id');
        });
    }
};
