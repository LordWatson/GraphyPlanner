<?php

use App\Models\Client;
use App\Services\ClientHealthService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step I-2.5 — store each client's health instead of computing it per row in PHP, so the
     * clients index can sort and filter on it in SQL. `RecomputeClientHealthAction` keeps it
     * current; `client_health_history` records every change (one row per transition) for the
     * health-over-time sparkline (Step I-6.1).
     */
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('health_status')->nullable()->after('status');
            $table->string('health_reason')->nullable()->after('health_status');
            $table->timestamp('health_computed_at')->nullable()->after('health_reason');

            $table->index(['org_id', 'health_status']);
            $table->index(['org_id', 'status']);
        });

        Schema::create('client_health_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->string('health_status');
            $table->string('health_reason');
            $table->timestamp('created_at')->nullable();

            $table->index(['client_id', 'created_at']);
        });

        $this->backfill();
    }

    public function down(): void
    {
        Schema::dropIfExists('client_health_history');

        Schema::table('clients', function (Blueprint $table) {
            // MySQL: `org_id` keeps its FK cover via the earlier `(org_id, name)` index.
            $table->dropIndex(['org_id', 'health_status']);
            $table->dropIndex(['org_id', 'status']);
            $table->dropColumn(['health_status', 'health_reason', 'health_computed_at']);
        });
    }

    private function backfill(): void
    {
        $service = app(ClientHealthService::class);
        $now = Carbon::now();

        Client::query()
            ->withoutGlobalScopes()
            ->with(['socialAccounts' => fn ($query) => $query->withoutGlobalScopes()->select(['id', 'client_id', 'connection_status'])])
            ->orderBy('id')
            ->chunkById(200, function ($clients) use ($service, $now) {
                foreach ($clients as $client) {
                    $health = $service->compute($client);

                    DB::table('clients')->where('id', $client->id)->update([
                        'health_status' => $health['status']->value,
                        'health_reason' => $health['reason'],
                        'health_computed_at' => $now,
                    ]);

                    DB::table('client_health_history')->insert([
                        'client_id' => $client->id,
                        'health_status' => $health['status']->value,
                        'health_reason' => $health['reason'],
                        'created_at' => $now,
                    ]);
                }
            });
    }
};
