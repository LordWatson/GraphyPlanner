<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step CG-5.2 (B-11.2) — the brand-deals tracker: who the creator is working with, what they owe,
 * what it pays, and what has actually gone out.
 *
 * - `deliverables` is json (`[{platform, quantity}]`) rather than its own table: progress is
 *   **derived** by counting the deal's own published posts per platform, so there is no per-row
 *   state to keep in step with reality.
 * - `fee_minor` is minor units like everything else that touches money (`creator-store.md`).
 * - `show_on_media_kit` is the opt-in for the kit's "past partners" — off by default, because a
 *   sponsor relationship isn't ours to publish.
 * - `contact_email` is only set by an enquiry that came in through the media kit (CG-5.3).
 * - `posts.brand_deal_id` links a post to the deal it delivers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brand_deals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('org_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->string('partner_name');
            $table->string('partner_handle', 64)->nullable();
            $table->string('status', 16)->default('pitched');
            $table->unsignedInteger('fee_minor')->nullable();
            $table->string('currency', 3)->nullable();
            $table->json('deliverables')->nullable();
            $table->date('starts_on')->nullable();
            $table->date('due_on')->nullable();
            $table->text('notes')->nullable();
            $table->string('contact_email')->nullable();
            $table->boolean('show_on_media_kit')->default(false);
            $table->timestamps();

            $table->index(['client_id', 'status']);
            $table->index('org_id');
            $table->index('due_on');
        });

        Schema::table('posts', function (Blueprint $table) {
            $table->foreignId('brand_deal_id')->nullable()->after('partner_handle')
                ->constrained('brand_deals')->nullOnDelete();

            $table->index('brand_deal_id');
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropIndex(['brand_deal_id']);
            $table->dropConstrainedForeignId('brand_deal_id');
        });

        Schema::dropIfExists('brand_deals');
    }
};
