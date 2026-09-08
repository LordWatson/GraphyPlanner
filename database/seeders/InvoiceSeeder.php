<?php

namespace Database\Seeders;

use App\Enums\InvoiceStatus;
use App\Models\Client;
use App\Models\Invoice;
use Illuminate\Database\Seeder;

class InvoiceSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $recommendo = Client::firstWhere('name', 'Recommendo');
        $frzn = Client::firstWhere('name', 'Frzn');

        if ($recommendo) {
            $this->seedInvoicesFor($recommendo, [
                [
                    'invoice_number' => 'INV-000001',
                    'status' => InvoiceStatus::Paid,
                    'amount' => 2500,
                    'issue_date' => now()->subMonths(3)->startOfMonth()->toDateString(),
                    'due_date' => now()->subMonths(3)->addDays(15)->toDateString(),
                    'description' => 'Monthly retainer — month 1',
                    'sent_at' => now()->subMonths(3)->addDay(),
                    'paid_at' => now()->subMonths(3)->addDays(10),
                ],
                [
                    'invoice_number' => 'INV-000002',
                    'status' => InvoiceStatus::Paid,
                    'amount' => 2500,
                    'issue_date' => now()->subMonths(2)->startOfMonth()->toDateString(),
                    'due_date' => now()->subMonths(2)->addDays(15)->toDateString(),
                    'description' => 'Monthly retainer — month 2',
                    'sent_at' => now()->subMonths(2)->addDay(),
                    'paid_at' => now()->subMonths(2)->addDays(12),
                ],
                [
                    'invoice_number' => 'INV-000003',
                    'status' => InvoiceStatus::Sent,
                    'amount' => 2500,
                    'issue_date' => now()->subMonth()->startOfMonth()->toDateString(),
                    'due_date' => now()->subMonth()->addDays(15)->toDateString(),
                    'description' => 'Monthly retainer — month 3',
                    'sent_at' => now()->subMonth()->addDay(),
                    'paid_at' => null,
                ],
                [
                    'invoice_number' => 'INV-000004',
                    'status' => InvoiceStatus::Draft,
                    'amount' => 2500,
                    'issue_date' => now()->startOfMonth()->toDateString(),
                    'due_date' => now()->startOfMonth()->addDays(15)->toDateString(),
                    'description' => 'Monthly retainer — month 4',
                    'sent_at' => null,
                    'paid_at' => null,
                ],
            ]);
        }

        if ($frzn) {
            $this->seedInvoicesFor($frzn, [
                [
                    'invoice_number' => 'INV-000005',
                    'status' => InvoiceStatus::Paid,
                    'amount' => 1200,
                    'issue_date' => now()->subMonths(3)->startOfQuarter()->toDateString(),
                    'due_date' => now()->subMonths(3)->startOfQuarter()->addDays(30)->toDateString(),
                    'description' => 'Quarterly retainer — Q1',
                    'sent_at' => now()->subMonths(3),
                    'paid_at' => now()->subMonths(2)->subDays(20),
                ],
                [
                    'invoice_number' => 'INV-000006',
                    'status' => InvoiceStatus::Sent,
                    'amount' => 1200,
                    'issue_date' => now()->startOfQuarter()->toDateString(),
                    'due_date' => now()->startOfQuarter()->addDays(30)->toDateString(),
                    'description' => 'Quarterly retainer — Q2',
                    'sent_at' => now()->subDays(5),
                    'paid_at' => null,
                ],
            ]);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $invoices
     */
    private function seedInvoicesFor(Client $client, array $invoices): void
    {
        foreach ($invoices as $invoice) {
            Invoice::updateOrCreate(
                ['invoice_number' => $invoice['invoice_number']],
                [
                    'org_id' => $client->org_id,
                    'client_id' => $client->id,
                    'currency' => 'USD',
                    ...$invoice,
                ]
            );
        }
    }
}
