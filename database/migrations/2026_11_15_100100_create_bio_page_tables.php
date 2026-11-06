<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase CG-3 (B-11.3) — link in bio.
 *
 * - `bio_pages`: one page per primary brand (`client_id` unique), reached publicly at `/@{handle}`.
 *   `handle` is globally unique (it's the public namespace) and `published_at` is the only thing
 *   that makes the page resolve — an unpublished page 404s like the media kit's revoked link.
 *   `theme` stores a brand token name, never a raw hex, so the page can only ever use the design
 *   system's palette.
 * - `bio_blocks`: the ordered blocks of a page. `type` is `App\Enums\BioBlockType`; `settings`
 *   carries the per-type payload (a link's label + url, how many latest posts to show, …).
 * - `bio_events`: views and clicks rolled up per day (`bio_block_id` null = a page view), so the
 *   table stays tiny and no IP, user agent or other visitor data is ever stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bio_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('org_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('client_id')->unique()->constrained('clients')->cascadeOnDelete();
            $table->string('handle', 32)->unique();
            $table->string('title')->nullable();
            $table->string('bio', 500)->nullable();
            $table->string('theme', 32)->default('cream');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['org_id', 'published_at']);
        });

        Schema::create('bio_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bio_page_id')->constrained('bio_pages')->cascadeOnDelete();
            $table->string('type', 32);
            $table->unsignedSmallInteger('position');
            $table->json('settings')->nullable();
            $table->boolean('is_visible')->default(true);
            $table->timestamps();

            $table->index(['bio_page_id', 'position']);
        });

        Schema::create('bio_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bio_page_id')->constrained('bio_pages')->cascadeOnDelete();
            // Null = a view of the page itself; set = a click on one of its blocks.
            $table->foreignId('bio_block_id')->nullable()->constrained('bio_blocks')->cascadeOnDelete();
            $table->date('date');
            $table->unsignedInteger('count')->default(0);

            // Both engines treat NULLs as distinct in a unique index, so this constrains click rows
            // only; page-view rows can race into duplicates. Reads always SUM, so the total stays
            // right either way (App\Actions\Business\RecordBioEventAction).
            $table->unique(['bio_page_id', 'bio_block_id', 'date'], 'bio_events_page_block_date_unique');
            $table->index('bio_block_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bio_events');
        Schema::dropIfExists('bio_blocks');
        Schema::dropIfExists('bio_pages');
    }
};
