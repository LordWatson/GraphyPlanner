<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step M-6.3 — how a client's posts are approved across its approver contacts: any one, all, or
     * in sequence. Defaults to `any`, which is the original single-approver behaviour.
     */
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('approval_policy', 20)->default('any')->after('default_approval_mode');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('approval_policy');
        });
    }
};
