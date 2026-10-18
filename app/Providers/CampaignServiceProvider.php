<?php

namespace App\Providers;

use App\Contracts\CampaignAiProvider;
use App\Contracts\CampaignFeatures;
use App\Services\AI\DeepSeekProvider;
use App\Services\AI\NullAiProvider;
use App\Services\CampaignHealthService;
use App\Services\Campaigns\Health\BriefNotApprovedRule;
use App\Services\Campaigns\Health\DeliverablesNotStartedRule;
use App\Services\Campaigns\Health\ExpiredAccountOnScheduledPostRule;
use App\Services\Campaigns\Health\LaunchingWithUnapprovedWorkRule;
use App\Services\Campaigns\Health\MissingBasicsRule;
use App\Services\Campaigns\Health\PlannedPlatformNotConnectedRule;
use App\Services\Campaigns\Health\StatusDateMismatchRule;
use App\Services\Campaigns\SettingsCampaignFeatures;
use Illuminate\Support\ServiceProvider;

/**
 * The campaigns module's container wiring (`.junie/plans/campaigns-enhancement.md`).
 *
 * Health rules are tagged so each plan phase adds a rule here without touching
 * `CampaignHealthService`. Within a level, the first rule listed supplies the headline reason.
 */
class CampaignServiceProvider extends ServiceProvider
{
    public const HEALTH_RULES = 'campaign.health-rules';

    public function register(): void
    {
        $this->app->tag([
            // Red.
            LaunchingWithUnapprovedWorkRule::class,
            ExpiredAccountOnScheduledPostRule::class,
            StatusDateMismatchRule::class,
            // Amber.
            BriefNotApprovedRule::class,
            DeliverablesNotStartedRule::class,
            PlannedPlatformNotConnectedRule::class,
            MissingBasicsRule::class,
        ], self::HEALTH_RULES);

        $this->app->singleton(CampaignHealthService::class, fn ($app) => new CampaignHealthService($app->tagged(self::HEALTH_RULES)));

        // C-2.2 / C-3.3 / C-7.3: same rule as `AiProvider` — DeepSeek only when AI is enabled and
        // a key is set, otherwise the Null provider (empty, valid suggestions, no vendor call).
        // C-9.4: settings today; a plan-entitlement implementation replaces this binding later.
        $this->app->singleton(CampaignFeatures::class, SettingsCampaignFeatures::class);

        $this->app->bind(CampaignAiProvider::class, function ($app) {
            return (bool) config('ai.enabled', false) && (string) config('ai.deepseek.api_key', '') !== ''
                ? $app->make(DeepSeekProvider::class)
                : $app->make(NullAiProvider::class);
        });
    }
}
