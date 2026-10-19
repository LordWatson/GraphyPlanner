<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step I-3.8 / M-2.9 — the approval mode a client's new posts start with. `client_required`
     * (the safe default for an agency) means a post can only be approved through the client.
     */
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('default_approval_mode', 32)->default('client_required')->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('default_approval_mode');
        });
    }
};
