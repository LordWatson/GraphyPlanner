<?php

namespace App\Console\Commands;

use App\Actions\Plans\EndPlanGrandfatherAction;
use Illuminate\Console\Command;

class EndPlanGrandfatherCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'plans:end-grandfather';

    /**
     * @var string
     */
    protected $description = 'Move agency orgs off the grandfathered unlimited plan once their 12-month period has passed (Step GTM-0.1)';

    public function handle(EndPlanGrandfatherAction $action): int
    {
        $result = $action();

        $this->info("Moved {$result['moved']} org(s) off agency_legacy onto agency_standard.");

        return self::SUCCESS;
    }
}
