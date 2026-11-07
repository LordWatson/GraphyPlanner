<?php

namespace App\Console\Commands;

use App\Actions\Business\ReverifyBioPageDomainsAction;
use Illuminate\Console\Command;

/**
 * Step GTM-3.2 — nightly: re-check every bio-page custom domain, so one that has been repointed
 * stops serving and one whose DNS has just propagated starts.
 */
class ReverifyBioPageDomainsCommand extends Command
{
    protected $signature = 'bio-domains:reverify';

    protected $description = 'Re-check every bio page custom domain against DNS';

    public function handle(ReverifyBioPageDomainsAction $action): int
    {
        $result = $action();

        $this->info(sprintf(
            'Checked %d domain(s): %d newly verified, %d no longer pointing here.',
            $result['checked'],
            $result['gained'],
            $result['lost'],
        ));

        return self::SUCCESS;
    }
}
