<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step M-5.9 — an org's OAuth connection to a design/cloud source (Canva, Google Drive, Dropbox)
 * it imports media from. Tokens are stored encrypted (never logged). One live connection per
 * org + provider; revoking sets `revoked_at` rather than deleting, so history is kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('design_import_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('org_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('provider');
            // Encrypted at the model layer; sized for an encrypted-token payload.
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('account_label')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            // IndexCoverageTest: every FK column leads an index. The composite finds an org's live
            // connection for a provider.
            $table->index(['org_id', 'provider']);
            $table->index('created_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('design_import_connections');
    }
};
