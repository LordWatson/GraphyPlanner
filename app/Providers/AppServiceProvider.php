<?php

namespace App\Providers;

use App\Contracts\AssetStorage;
use App\Contracts\MusicProvider;
use App\Contracts\PublishAdapter;
use App\Models\Asset;
use App\Models\BrandBrain;
use App\Models\Campaign;
use App\Models\Client;
use App\Models\ClientInvitation;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\StaffInvitation;
use App\Models\User;
use App\Policies\AssetPolicy;
use App\Policies\BrandBrainPolicy;
use App\Policies\CampaignPolicy;
use App\Policies\ClientInvitationPolicy;
use App\Policies\ClientPolicy;
use App\Policies\InvoicePolicy;
use App\Policies\OrganizationPolicy;
use App\Policies\PostPolicy;
use App\Policies\SocialAccountPolicy;
use App\Policies\StaffInvitationPolicy;
use App\Policies\StaffPolicy;
use App\Services\LocalAssetStorage;
use App\Services\Publishing\UploadPostAdapter;
use App\Services\Publishing\UploadPostMusicProvider;
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

        // Upload-Post adapter (Step 1.2) bound against the PublishAdapter contract (Step 1.1).
        // No caller of App\Contracts\PublishAdapter needs to change; the connect flow, schedule
        // endpoint, webhook sync, and health integration still land in Steps 1.3-1.6.
        $this->app->bind(PublishAdapter::class, UploadPostAdapter::class);

        // Upload-Post music/sound-library provider (Step 1.8.2) bound against the MusicProvider
        // contract (Step 1.8.1). No caller of App\Contracts\MusicProvider needs to change; the
        // SearchMusicAction/editor music picker still land in Steps 1.8.3/1.8.4.
        $this->app->bind(MusicProvider::class, UploadPostMusicProvider::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        Gate::policy(Client::class, ClientPolicy::class);
        Gate::policy(ClientInvitation::class, ClientInvitationPolicy::class);
        Gate::policy(BrandBrain::class, BrandBrainPolicy::class);
        Gate::policy(Invoice::class, InvoicePolicy::class);
        Gate::policy(SocialAccount::class, SocialAccountPolicy::class);
        Gate::policy(Campaign::class, CampaignPolicy::class);
        Gate::policy(Asset::class, AssetPolicy::class);
        Gate::policy(Post::class, PostPolicy::class);
        Gate::policy(Organization::class, OrganizationPolicy::class);
        Gate::policy(StaffInvitation::class, StaffInvitationPolicy::class);
        Gate::policy(User::class, StaffPolicy::class);
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
