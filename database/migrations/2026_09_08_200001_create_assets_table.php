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
        Schema::create('assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('org_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained('campaigns')->nullOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source');
            $table->string('type')->nullable();

            // Populated when source = upload. "disk" is whatever `config('assets.disk')`
            // resolved to at upload time (local "public" disk today, S3 later — see
            // App\Contracts\AssetStorage) so old rows keep resolving correctly after a switch.
            $table->string('disk')->nullable();
            $table->string('path')->nullable();

            // Always populated: the storage-resolved URL for uploads, or the given
            // link for figma/url sources.
            $table->string('url')->nullable();

            $table->string('original_filename')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->text('rights')->nullable();
            $table->uuid('variant_group_id')->nullable();
            $table->timestamps();

            $table->index('variant_group_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('assets');
    }
};
