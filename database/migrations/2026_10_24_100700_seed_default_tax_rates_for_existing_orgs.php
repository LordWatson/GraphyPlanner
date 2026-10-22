<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Step M-4.1 — backfill the UK + UAE starter tax rates (from `config/tax.php`) for organizations
 * that already existed before tax rates were introduced. New organizations get them at signup via
 * `SeedDefaultTaxRatesAction`. Idempotent on `(org_id, name)`, so re-running changes nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        /** @var list<array{name: string, rate: float}> $defaults */
        $defaults = config('tax.defaults', []);
        $now = now();

        foreach (DB::table('organizations')->pluck('id') as $orgId) {
            foreach ($defaults as $index => $default) {
                $exists = DB::table('tax_rates')
                    ->where('org_id', $orgId)
                    ->where('name', $default['name'])
                    ->exists();

                if ($exists) {
                    continue;
                }

                DB::table('tax_rates')->insert([
                    'org_id' => $orgId,
                    'name' => $default['name'],
                    'rate' => $default['rate'],
                    'sort_order' => $index,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        // Leave the rates in place — removing them could orphan invoices that reference them.
    }
};
