<?php

namespace App\Console\Commands;

use App\Actions\SocialAccounts\CheckAllSocialAccountsHealthAction;
use App\Actions\SocialAccounts\CheckSocialAccountHealthAction;
use App\Contracts\PublishAdapter;
use Illuminate\Console\Command;

/**
 * Step 1.6 scheduled sweep — periodically re-checks every `connected` SocialAccount against the
 * publish vendor so an expired/revoked token is caught (and flows into ClientHealthService/Home)
 * even without the account being touched by a publish attempt.
 */
class CheckSocialAccountsHealthCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'social-accounts:check-health';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check every connected social account against the publish vendor and mark expired tokens';

    public function handle(
        CheckAllSocialAccountsHealthAction $action,
        CheckSocialAccountHealthAction $checkHealth,
        PublishAdapter $adapter,
    ): int {
        $flagged = $action($adapter, $checkHealth);

        $this->info("Checked connected social accounts, flagged {$flagged} as token_expired.");

        return self::SUCCESS;
    }
}
