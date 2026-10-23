<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Step M-6.1 — migrate the legacy single-address columns (`clients.approval_email`,
     * `clients.billing_email`) into `client_contacts`. For each client: the approval address becomes
     * an Approver contact that receives review emails, the billing address a Billing contact that
     * receives invoices, merged into one row when the two addresses are the same. Idempotent — skips
     * an address a contact already exists for (so a re-run, or running after rows exist, is safe).
     */
    public function up(): void
    {
        DB::table('clients')
            ->select('id', 'org_id', 'approval_email', 'billing_email')
            ->orderBy('id')
            ->chunkById(200, function ($clients) {
                $now = now();
                $rows = [];

                foreach ($clients as $client) {
                    /** @var array<string, array<string, mixed>> $byEmail keyed by lowercased email */
                    $byEmail = [];

                    $approval = trim((string) ($client->approval_email ?? ''));
                    if ($approval !== '' && filter_var($approval, FILTER_VALIDATE_EMAIL) !== false) {
                        $byEmail[strtolower($approval)] = [
                            'email' => $approval,
                            'role' => 'approver',
                            'receives_review_emails' => true,
                            'receives_invoices' => false,
                        ];
                    }

                    $billing = trim((string) ($client->billing_email ?? ''));
                    if ($billing !== '' && filter_var($billing, FILTER_VALIDATE_EMAIL) !== false) {
                        $key = strtolower($billing);
                        if (isset($byEmail[$key])) {
                            // Same person approves and is billed — one contact, both channels on.
                            $byEmail[$key]['receives_invoices'] = true;
                        } else {
                            $byEmail[$key] = [
                                'email' => $billing,
                                'role' => 'billing',
                                'receives_review_emails' => false,
                                'receives_invoices' => true,
                            ];
                        }
                    }

                    foreach ($byEmail as $contact) {
                        $exists = DB::table('client_contacts')
                            ->where('client_id', $client->id)
                            ->whereRaw('LOWER(email) = ?', [strtolower($contact['email'])])
                            ->whereNull('deleted_at')
                            ->exists();

                        if ($exists) {
                            continue;
                        }

                        $rows[] = [
                            'org_id' => $client->org_id,
                            'client_id' => $client->id,
                            'name' => null,
                            'email' => $contact['email'],
                            'phone' => null,
                            'role' => $contact['role'],
                            'receives_review_emails' => $contact['receives_review_emails'],
                            'receives_invoices' => $contact['receives_invoices'],
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                }

                if ($rows !== []) {
                    DB::table('client_contacts')->insert($rows);
                }
            });
    }

    public function down(): void
    {
        // The legacy columns are left intact, so a down migration just clears the backfilled rows.
        DB::table('client_contacts')->delete();
    }
};
