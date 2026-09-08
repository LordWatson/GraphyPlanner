<?php

namespace App\Providers;

use App\Contracts\AssetStorage;
use App\Models\Asset;
use App\Models\BrandBrain;
use App\Models\Campaign;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\SocialAccount;
use App\Policies\AssetPolicy;
use App\Policies\BrandBrainPolicy;
use App\Policies\CampaignPolicy;
use App\Policies\ClientPolicy;
use App\Policies\InvoicePolicy;
use App\Policies\SocialAccountPolicy;
use App\Services\LocalAssetStorage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Local disk today (config('assets.disk'), "public" by default); swap this
        // binding for an S3-backed implementation once Phase 1/2 needs signed-URL
        // uploads (spec §10) — no caller of App\Contracts\AssetStorage needs to change.
        $this->app->bind(AssetStorage::class, LocalAssetStorage::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        Gate::policy(Client::class, ClientPolicy::class);
        Gate::policy(BrandBrain::class, BrandBrainPolicy::class);
        Gate::policy(Invoice::class, InvoicePolicy::class);
        Gate::policy(SocialAccount::class, SocialAccountPolicy::class);
        Gate::policy(Campaign::class, CampaignPolicy::class);
        Gate::policy(Asset::class, AssetPolicy::class);
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
