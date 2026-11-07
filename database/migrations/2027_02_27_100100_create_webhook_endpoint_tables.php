<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Outgoing webhooks: an organization tells us a URL, we tell it when something happens.
 *
 * The other half of the integration story. A read API answers "what is the state of things"; a
 * webhook answers "tell me the moment it changes", which is what a CRM, a Slack workflow or a
 * Zapier-style connector actually needs — and it is far cheaper for both sides than polling.
 *
 * Deliveries are kept as rows rather than fired and forgotten, because the first question anyone
 * asks about a webhook is "did it arrive, and if not why" — and the second is "send it again".
 * Without a record neither has an answer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_endpoints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('org_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('url', 2048);
            // The signing secret the receiver verifies with. Encrypted: anyone holding it can forge
            // a delivery that the receiver will trust.
            $table->text('secret');
            // Which events this endpoint wants. A list, so one endpoint can take everything and
            // another only the one thing it cares about.
            $table->json('events');
            $table->string('description')->nullable();
            $table->timestamp('disabled_at')->nullable();
            // Why we stopped trying, so the UI can say more than "broken".
            $table->string('disabled_reason')->nullable();
            $table->timestamps();

            $table->index(['org_id', 'disabled_at']);
        });

        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('webhook_endpoint_id')->constrained('webhook_endpoints')->cascadeOnDelete();
            $table->foreignId('org_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('event', 64);
            $table->json('payload');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->string('error')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            // The dead-letter view: everything for one org that has not been delivered.
            $table->index(['org_id', 'delivered_at', 'failed_at']);
            $table->index(['webhook_endpoint_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhook_endpoints');
    }
};
