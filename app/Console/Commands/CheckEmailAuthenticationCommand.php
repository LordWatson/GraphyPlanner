<?php

namespace App\Console\Commands;

use App\Services\Mail\EmailAuthenticationCheck;
use Illuminate\Console\Command;

/**
 * `php artisan mail:check-dns` — reports whether the sending domain is authenticated.
 *
 * A command rather than a page because DNS is set once per environment and checked by whoever
 * deploys: this belongs in a release checklist, and it exits non-zero so it can simply be a step
 * in one. Nothing can send approval emails reliably until it passes.
 */
class CheckEmailAuthenticationCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'mail:check-dns {domain? : Override the domain from mail.from.address}';

    /**
     * @var string
     */
    protected $description = 'Check SPF, DKIM and DMARC on the sending domain';

    public function handle(EmailAuthenticationCheck $check): int
    {
        $domain = $this->argument('domain');
        $result = $check->check(is_string($domain) ? $domain : null);

        if ($result['domain'] === '') {
            $this->components->error('No sending domain — set MAIL_FROM_ADDRESS first.');

            return self::FAILURE;
        }

        $this->components->info("Email authentication for {$result['domain']}");

        foreach (['spf' => 'SPF', 'dkim' => 'DKIM', 'dmarc' => 'DMARC'] as $key => $label) {
            /** @var array{ok: bool, name: string, found: list<string>, detail: string} $record */
            $record = $result[$key];

            $this->components->twoColumnDetail(
                sprintf('%s <fg=gray>%s</>', $label, $record['name']),
                $record['ok'] ? '<fg=green>pass</>' : '<fg=red>FAIL</>',
            );
            $this->line("   {$record['detail']}");
        }

        $this->newLine();

        if (! $result['ok']) {
            $this->components->warn(
                'Approval requests, invitations and invoices from this domain will land in spam for '
                .'some recipients until these are published.'
            );

            return self::FAILURE;
        }

        $this->components->info('Authenticated.');

        return self::SUCCESS;
    }
}
