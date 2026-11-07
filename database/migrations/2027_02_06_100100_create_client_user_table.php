<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-client staff access.
 *
 * Until now a teammate's access was one org-wide `users.role`: every Strategist and Designer could
 * open every client's brand brain, captions, analytics, invoices, inbox and media. That is the most
 * repeated complaint in the agency research — "junior team members end up with access to every
 * client's account" — and for an agency it is a confidentiality problem rather than a preference:
 * freelancers are normal, client contracts restrict who may see the work, and two competing brands
 * are often on the same roster.
 *
 * `restrict_staff_to_assigned_clients` defaults to **false**, so nothing changes for an existing
 * organization until somebody turns it on. An Owner is never restricted — somebody has to be able
 * to see the whole roster.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            // One assignment per pair, and the index the access lookup reads.
            $table->unique(['client_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::table('organizations', function (Blueprint $table) {
            $table->boolean('restrict_staff_to_assigned_clients')->default(false)->after('require_post_review');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('restrict_staff_to_assigned_clients');
        });

        Schema::dropIfExists('client_user');
    }
};
