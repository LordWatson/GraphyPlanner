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
        Schema::table('invoices', function (Blueprint $table) {
            // Mirrors BrandBrain's persona_* columns (see App\Contracts\AssetStorage) so a switch
            // to another storage backend later doesn't require touching Invoice PDF code either.
            $table->string('pdf_disk')->nullable()->after('paid_at');
            $table->string('pdf_path')->nullable()->after('pdf_disk');
            $table->string('pdf_url')->nullable()->after('pdf_path');
            $table->string('pdf_original_filename')->nullable()->after('pdf_url');
            $table->string('pdf_mime_type')->nullable()->after('pdf_original_filename');
            $table->unsignedBigInteger('pdf_size')->nullable()->after('pdf_mime_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['pdf_disk', 'pdf_path', 'pdf_url', 'pdf_original_filename', 'pdf_mime_type', 'pdf_size']);
        });
    }
};
