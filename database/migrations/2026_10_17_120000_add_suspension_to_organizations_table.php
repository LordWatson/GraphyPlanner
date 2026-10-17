<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step 15.3: a super admin can suspend an organization (soft, reversible lock-out of every
     * member, distinct from hard delete). `suspended_by` is the super admin who did it.
     */
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->timestamp('suspended_at')->nullable();
            $table->text('suspension_reason')->nullable();
            $table->foreignId('suspended_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('suspended_by');
            $table->dropColumn(['suspended_at', 'suspension_reason']);
        });
    }
};
