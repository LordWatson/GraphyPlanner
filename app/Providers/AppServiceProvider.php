<?php

namespace App\Providers;

use App\Contracts\AssetStorage;
use App\Contracts\PublishAdapter;
use App\Models\Asset;
use App\Models\BrandBrain;
use App\Models\Campaign;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Policies\AssetPolicy;
use App\Policies\BrandBrainPolicy;
use App\Policies\CampaignPolicy;
use App\Policies\ClientPolicy;
use App\Policies\InvoicePolicy;
use App\Policies\OrganizationPolicy;
use App\Policies\PostPolicy;
use App\Policies\SocialAccountPolicy;
use App\Services\LocalAssetStorage;
use App\Services\Publishing\NullPublishAdapter;
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

        // No real vendor yet (Step 1.1) — swap this binding for the
        // Upload-Post adapter once Step 1.2 implements PublishAdapter
        // against their API. No caller of App\Contracts\PublishAdapter needs
        // to change.
        $this->app->bind(PublishAdapter::class, NullPublishAdapter::class);
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
        Gate::policy(Post::class, PostPolicy::class);
        Gate::policy(Organization::class, OrganizationPolicy::class);
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
