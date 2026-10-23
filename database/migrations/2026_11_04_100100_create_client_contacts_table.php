<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step M-6.1 — named contacts at a client. `clients.approval_email` held a single approver and
     * `clients.billing_email` a single billing address; this table lets a client have many people,
     * each with a role and per-channel email preferences (review emails, invoices). The legacy
     * single-address columns are backfilled into this table by the next migration and kept in sync
     * for now so no existing read path breaks.
     */
    public function up(): void
    {
        Schema::create('client_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('org_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->string('name')->nullable();
            $table->string('email');
            $table->string('phone')->nullable();
            $table->string('role', 32)->default('other');
            $table->boolean('receives_review_emails')->default(false);
            $table->boolean('receives_invoices')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['client_id', 'role']);
            $table->index('org_id');
            // Uniqueness of a live (client, email) is enforced in the app layer, not here: a UNIQUE
            // that included `deleted_at` would not constrain live rows (NULLs compare distinct on
            // both MySQL and SQLite), so the store/update Form Requests carry the rule instead.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_contacts');
    }
};
