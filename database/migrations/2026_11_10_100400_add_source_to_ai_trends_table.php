<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step M-7.5 (dev plan I-9.5) — "honest trends". Each persisted trend records where it came
     * from ("AI hypothesis", "Your top posts", …) so the UI can label model guesswork distinctly
     * from the brand's own real signal. Null for rows discovered before this column existed.
     */
    public function up(): void
    {
        Schema::table('ai_trends', function (Blueprint $table) {
            $table->string('source')->nullable()->after('confidence');
        });
    }

    public function down(): void
    {
        Schema::table('ai_trends', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
