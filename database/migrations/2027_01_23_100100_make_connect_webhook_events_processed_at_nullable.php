<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Step CG-4.1 fix — `connect_webhook_events.processed_at` becomes nullable, so the row can record
 * a *claim* that is not yet a *completion*.
 *
 * `ConnectWebhookController` used to insert the row with `processed_at = now()` before doing any
 * work. If the handler then threw, the 500 sent Stripe away to retry, the retry found the id
 * already recorded, called it a replay and answered 200 — a charged card with an order left
 * `pending` forever and no further deliveries coming. The row is now written without a timestamp,
 * stamped only once the handler has finished, and deleted if the handler fails.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('connect_webhook_events', function (Blueprint $table) {
            $table->timestamp('processed_at')->nullable()->change();
        });
    }

    public function down(): void
    {
        // An unfinished claim cannot be represented by the old NOT NULL column. Drop those rows
        // rather than invent a completion time: "never claimed" is the truthful reading, and it
        // lets the event be processed again.
        DB::table('connect_webhook_events')->whereNull('processed_at')->delete();

        Schema::table('connect_webhook_events', function (Blueprint $table) {
            $table->timestamp('processed_at')->nullable(false)->change();
        });
    }
};
