<?php

namespace App\Providers;

use App\Contracts\AiProvider;
use App\Contracts\AssetStorage;
use App\Contracts\ImageProvider;
use App\Contracts\MusicProvider;
use App\Contracts\PublishAdapter;
use App\Models\AiImageGeneration;
use App\Models\Asset;
use App\Models\BrandBrain;
use App\Models\Campaign;
use App\Models\Client;
use App\Models\ClientInvitation;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Organization;
use App\Models\OrganizationReport;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\StaffInvitation;
use App\Models\SuperAdminReport;
use App\Models\User;
use App\Policies\AiImageGenerationPolicy;
use App\Policies\AssetPolicy;
use App\Policies\BrandBrainPolicy;
use App\Policies\CampaignPolicy;
use App\Policies\ClientInvitationPolicy;
use App\Policies\ClientPolicy;
use App\Policies\InvoiceItemPolicy;
use App\Policies\InvoicePolicy;
use App\Policies\OrganizationPolicy;
use App\Policies\OrganizationReportPolicy;
use App\Policies\PostPolicy;
use App\Policies\SocialAccountPolicy;
use App\Policies\StaffInvitationPolicy;
use App\Policies\StaffPolicy;
use App\Policies\SuperAdminReportPolicy;
use App\Services\AI\DeepSeekProvider;
use App\Services\AI\Images\GeminiImageProvider;
use App\Services\AI\Images\NullImageProvider;
use App\Services\AI\Images\OpenAiImageProvider;
use App\Services\AI\NullAiProvider;
use App\Services\LocalAssetStorage;
use App\Services\Publishing\MetaGraphMusicProvider;
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

        // MusicProvider contract (Step 1.8.1) bound to MetaGraphMusicProvider (Step 1.9.4), a
        // combinator that calls Meta's Graph API directly for Instagram audio search and
        // delegates every other platform (TikTok) unchanged to UploadPostMusicProvider
        // (Step 1.8.2, still bound below as its own concrete class). No caller of
        // App\Contracts\MusicProvider needs to change.
        $this->app->bind(MusicProvider::class, MetaGraphMusicProvider::class);

        // AiProvider contract (Step 2.1) resolves to DeepSeekProvider (Step 2.2) only when
        // AI is enabled (config('ai.enabled'), env AI_ENABLED) AND a non-empty DEEPSEEK_API_KEY
        // is configured; otherwise it safely falls back to NullAiProvider, which never calls an
        // external service. No caller of App\Contracts\AiProvider needs to change.
        $this->app->bind(AiProvider::class, function () {
            $enabled = (bool) config('ai.enabled', false);
            $apiKey = (string) config('ai.deepseek.api_key', '');

            if ($enabled && $apiKey !== '') {
                return $this->app->make(DeepSeekProvider::class);
            }

            return $this->app->make(NullAiProvider::class);
        });

        // ImageProvider contract (Step 16.1) resolves to the configured vendor (Gemini, Step 16.6,
        // or OpenAI, Step 16.12) only when AI is enabled AND that vendor's key is set, the same
        // rule as AiProvider above. Otherwise NullImageProvider renders placeholders, no vendor call.
        $this->app->bind(ImageProvider::class, function () {
            $provider = (string) config('ai.images.provider', 'null');
            $hasKey = (string) config("ai.images.{$provider}.api_key", '') !== '';

            if (! (bool) config('ai.enabled', false) || ! $hasKey) {
                return $this->app->make(NullImageProvider::class);
            }

            return match ($provider) {
                'gemini' => $this->app->make(GeminiImageProvider::class),
                'openai' => $this->app->make(OpenAiImageProvider::class),
                default => $this->app->make(NullImageProvider::class),
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        // Step 15.1: platform super admins bypass every per-org policy. They never reach the org
        // workspace itself (`org.member` sends them to `/super-admin`), so in practice this grants
        // the platform-level abilities on `OrganizationPolicy`/`SuperAdminReportPolicy`.
        Gate::before(fn (User $user): ?bool => $user->isSuperAdmin() ? true : null);

        Gate::policy(Client::class, ClientPolicy::class);
        Gate::policy(ClientInvitation::class, ClientInvitationPolicy::class);
        Gate::policy(BrandBrain::class, BrandBrainPolicy::class);
        Gate::policy(Invoice::class, InvoicePolicy::class);
        Gate::policy(InvoiceItem::class, InvoiceItemPolicy::class);
        Gate::policy(SocialAccount::class, SocialAccountPolicy::class);
        Gate::policy(Campaign::class, CampaignPolicy::class);
        Gate::policy(Asset::class, AssetPolicy::class);
        Gate::policy(AiImageGeneration::class, AiImageGenerationPolicy::class);
        Gate::policy(Post::class, PostPolicy::class);
        Gate::policy(Organization::class, OrganizationPolicy::class);
        Gate::policy(StaffInvitation::class, StaffInvitationPolicy::class);
        Gate::policy(User::class, StaffPolicy::class);
        Gate::policy(SuperAdminReport::class, SuperAdminReportPolicy::class);
        Gate::policy(OrganizationReport::class, OrganizationReportPolicy::class);
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
