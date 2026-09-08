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
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('org_id')->nullable()->after('id')->constrained('organizations')->nullOnDelete();
            $table->string('role')->nullable()->after('org_id');
            $table->unsignedBigInteger('client_id')->nullable()->after('role');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('org_id');
            $table->dropColumn(['role', 'client_id']);
        });
    }
};
