<?php

namespace App\Providers;

use App\Contracts\AiProvider;
use App\Contracts\AssetStorage;
use App\Contracts\CaptionRepurposer;
use App\Contracts\ChatNotifier;
use App\Contracts\CommentSource;
use App\Contracts\EngagementSource;
use App\Contracts\EventCalendar;
use App\Contracts\GridAiProvider;
use App\Contracts\ImageProvider;
use App\Contracts\InsightsSummarizer;
use App\Contracts\LocalIdeaProvider;
use App\Contracts\MediaProber;
use App\Contracts\MusicProvider;
use App\Contracts\ProfileFeedSource;
use App\Contracts\PublishAdapter;
use App\Contracts\PublishCredentials;
use App\Contracts\PushNotifier;
use App\Contracts\TrendSource;
use App\Contracts\VirusScanner;
use App\Contracts\VisionProvider;
use App\Contracts\WebsiteSummarizer;
use App\Contracts\ReplyAssistant;
use App\Contracts\WritingAssistant;
use App\Models\AiImageGeneration;
use App\Models\Asset;
use App\Models\BrandBrain;
use App\Models\Campaign;
use App\Models\Client;
use App\Models\ClientInvitation;
use App\Models\ClientReport;
use App\Models\GridPlan;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InboxConversation;
use App\Models\Organization;
use App\Models\OrganizationReport;
use App\Models\Post;
use App\Models\SavedReply;
use App\Models\SocialAccount;
use App\Models\StaffInvitation;
use App\Models\SuperAdminReport;
use App\Models\TaxRate;
use App\Models\User;
use App\Policies\AiImageGenerationPolicy;
use App\Policies\AssetPolicy;
use App\Policies\BrandBrainPolicy;
use App\Policies\CampaignPolicy;
use App\Policies\ClientInvitationPolicy;
use App\Policies\ClientPolicy;
use App\Policies\ClientReportPolicy;
use App\Policies\GridPlanPolicy;
use App\Policies\InvoiceItemPolicy;
use App\Policies\InvoicePolicy;
use App\Policies\InboxConversationPolicy;
use App\Policies\OrganizationPolicy;
use App\Policies\OrganizationReportPolicy;
use App\Policies\PostPolicy;
use App\Policies\SavedReplyPolicy;
use App\Policies\SocialAccountPolicy;
use App\Policies\StaffInvitationPolicy;
use App\Policies\StaffPolicy;
use App\Policies\SuperAdminReportPolicy;
use App\Policies\TaxRatePolicy;
use App\Services\AI\DeepSeekProvider;
use App\Services\AI\GeminiVisionProvider;
use App\Services\AI\Images\GeminiImageProvider;
use App\Services\AI\Images\NullImageProvider;
use App\Services\AI\Images\OpenAiImageProvider;
use App\Services\AI\NullAiProvider;
use App\Services\AI\NullVisionProvider;
use App\Services\AI\Trends\TopPostsTrendSource;
use App\Services\Analytics\AnalyticsCache;
use App\Services\Analytics\Engagement\UploadPostEngagementSource;
use App\Services\Inbox\UploadPostCommentSource;
use App\Services\Events\StaticEventCalendar;
use App\Services\Grids\CompositeProfileFeedSource;
use App\Services\LocalAssetStorage;
use App\Services\Media\FfprobeMediaProber;
use App\Services\Notifications\NullChatNotifier;
use App\Services\Posts\PostWorkflowResolver;
use App\Services\Publishing\MetaGraphMusicProvider;
use App\Services\Publishing\OrganizationOrPlatformCredentials;
use App\Services\Publishing\UploadPostAdapter;
use App\Services\Publishing\UploadPostMusicProvider;
use App\Services\Push\WebPushNotifier;
use App\Services\Security\ClamAvVirusScanner;
use App\Services\Security\NullVirusScanner;
use App\Services\SharedPropsCache;
use App\Services\Web\HttpWebsiteSummarizer;
use App\Services\Workspace\CurrentWorkspace;
use App\Support\Queues;
use App\Support\RateLimits;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\DevCommands;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
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
        // Step B-1.3: one workspace resolver per request, so the primary brand is resolved and
        // cached once (bound `scoped` so it never leaks across requests under Octane/queue reuse).
        $this->app->scoped(CurrentWorkspace::class);

        // Step B-3.1: the per-org-type post workflow resolver. Stateless, so a singleton is safe;
        // its two workflow implementations are stateless too. No caller reads
        // `PostStatusTransitionMap` directly for "allowed next steps" any more — they ask this.
        $this->app->singleton(PostWorkflowResolver::class);

        // Local disk today (config('assets.disk'), "public" by default); swap this
        // binding for an S3-backed implementation once Phase 1/2 needs signed-URL
        // uploads (spec §10) — no caller of App\Contracts\AssetStorage needs to change.
        $this->app->bind(AssetStorage::class, LocalAssetStorage::class);

        // Step I-3.5 / M-2.4: video probing + frame grabs for thumbnails (ffprobe/ffmpeg).
        $this->app->bind(MediaProber::class, FfprobeMediaProber::class);

        // Step B-7.4: web push for business workspaces — delivery behind a contract so the VAPID
        // transport can be swapped and faked. The default logs when no transport is installed.
        $this->app->bind(PushNotifier::class, WebPushNotifier::class);

        // Step M-6.7 (I-12.1): team-chat notifications behind a contract. The MVP is a no-op; a
        // WhatsApp implementation (not Slack) drops in here later without touching callers.
        $this->app->bind(ChatNotifier::class, NullChatNotifier::class);

        // Step B-2.4: the brand-profile quick-start fetches a brand's own website behind this
        // contract; the HTTP implementation is SSRF-guarded (never follows a URL to a private
        // address) and returns null on any failure. Faked in tests.
        $this->app->bind(WebsiteSummarizer::class, HttpWebsiteSummarizer::class);

        // Step I-16.2 / M-1.2: upload virus scanning — ClamAV when configured, otherwise none.
        $this->app->bind(VirusScanner::class, fn () => config('security.uploads.virus_scanner') === 'clamav'
            ? $this->app->make(ClamAvVirusScanner::class)
            : $this->app->make(NullVirusScanner::class));

        // Upload-Post adapter (Step 1.2) bound against the PublishAdapter contract (Step 1.1).
        // No caller of App\Contracts\PublishAdapter needs to change; the connect flow, schedule
        // endpoint, webhook sync, and health integration still land in Steps 1.3-1.6.
        $this->app->bind(PublishAdapter::class, UploadPostAdapter::class);

        // Step B-5.1: the single seam for vendor credentials — byo (the org's own key) for
        // agencies, managed (Alima's platform key + a per-org profile) for business workspaces.
        // The adapter, the webhook verifier and the music/engagement readers all resolve keys and
        // the publish profile through this contract, never from the model directly.
        $this->app->bind(PublishCredentials::class, OrganizationOrPlatformCredentials::class);

        // Step M-3.1: engagement metrics (M-0.2 audit — Upload-Post reports all nine networks).
        $this->app->bind(EngagementSource::class, UploadPostEngagementSource::class);

        // Step M-9.1: social-inbox comments (M-0.2 audit — Upload-Post covers seven networks;
        // Pinterest and Google Business are `comments => false`). Kept its own contract so a Meta
        // Graph fallback can slot in without touching publishing or analytics.
        $this->app->bind(CommentSource::class, UploadPostCommentSource::class);

        // MusicProvider contract (Step 1.8.1) bound to MetaGraphMusicProvider (Step 1.9.4), a
        // combinator that calls Meta's Graph API directly for Instagram audio search and
        // delegates every other platform (TikTok) unchanged to UploadPostMusicProvider
        // (Step 1.8.2, still bound below as its own concrete class). No caller of
        // App\Contracts\MusicProvider needs to change.
        $this->app->bind(MusicProvider::class, MetaGraphMusicProvider::class);

        // Step 18.3: the grid preview's live profile feed — Meta's Graph API when the account has
        // done the Facebook Login connect, else the posts the app published; never throws.
        $this->app->bind(ProfileFeedSource::class, CompositeProfileFeedSource::class);

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

        // Step 18.12: the grid planner's AI help — same rule as AiProvider (DeepSeek only when AI is
        // enabled and a key is set; otherwise the Null provider, which never calls a vendor).
        $this->app->bind(GridAiProvider::class, function () {
            return (bool) config('ai.enabled', false) && (string) config('ai.deepseek.api_key', '') !== ''
                ? $this->app->make(DeepSeekProvider::class)
                : $this->app->make(NullAiProvider::class);
        });

        // Step B-6.1: the business shell's local/seasonal idea engine — same DeepSeek-or-Null rule,
        // kept as its own contract so the agency AiProvider path (and its test doubles) is untouched.
        $this->app->bind(LocalIdeaProvider::class, function () {
            return (bool) config('ai.enabled', false) && (string) config('ai.deepseek.api_key', '') !== ''
                ? $this->app->make(DeepSeekProvider::class)
                : $this->app->make(NullAiProvider::class);
        });

        // Step B-6.4: repurpose-across-platforms — same DeepSeek-or-Null rule, its own contract.
        $this->app->bind(CaptionRepurposer::class, function () {
            return (bool) config('ai.enabled', false) && (string) config('ai.deepseek.api_key', '') !== ''
                ? $this->app->make(DeepSeekProvider::class)
                : $this->app->make(NullAiProvider::class);
        });

        // Step M-7.5: honest trends — the real-signal source is the client's own top posts. Its own
        // contract so Google Trends / TikTok Creative Center can slot in later, and so tests swap it.
        $this->app->bind(TrendSource::class, TopPostsTrendSource::class);

        // Step M-7.3: editor writing tools (shorten/tone/adapt) — same DeepSeek-or-Null rule.
        $this->app->bind(WritingAssistant::class, function () {
            return (bool) config('ai.enabled', false) && (string) config('ai.deepseek.api_key', '') !== ''
                ? $this->app->make(DeepSeekProvider::class)
                : $this->app->make(NullAiProvider::class);
        });

        // Step M-9.3: on-brand inbox reply drafts — same DeepSeek-or-Null rule. A human always sends.
        $this->app->bind(ReplyAssistant::class, function () {
            return (bool) config('ai.enabled', false) && (string) config('ai.deepseek.api_key', '') !== ''
                ? $this->app->make(DeepSeekProvider::class)
                : $this->app->make(NullAiProvider::class);
        });

        // Step B-8.2: plain-language insight takeaways — same DeepSeek-or-Null rule, its own contract.
        $this->app->bind(InsightsSummarizer::class, function () {
            return (bool) config('ai.enabled', false) && (string) config('ai.deepseek.api_key', '') !== ''
                ? $this->app->make(DeepSeekProvider::class)
                : $this->app->make(NullAiProvider::class);
        });

        // Step B-6.1: upcoming local holidays/awareness days that ground the idea engine. A static
        // dataset first (open question §5.5); swappable for a paid API behind the same contract.
        $this->app->singleton(EventCalendar::class, StaticEventCalendar::class);

        // Step B-6.2: "caption from a photo" — Gemini (the image vendor's multimodal models) only
        // when AI is enabled, the vision provider is `gemini` and a key is set; else the Null
        // provider (empty suggestions, no vendor call), so the editor degrades gracefully.
        $this->app->bind(VisionProvider::class, function () {
            $usable = (bool) config('ai.enabled', false)
                && config('ai.vision.provider') === 'gemini'
                && (string) config('ai.vision.api_key', '') !== '';

            return $usable ? $this->app->make(GeminiVisionProvider::class) : $this->app->make(NullVisionProvider::class);
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

        // Step I-2.6: the notification bell's cached unread count changes when one is stored.
        Event::listen(function (NotificationSent $event): void {
            if ($event->channel === 'database' && $event->notifiable instanceof User) {
                SharedPropsCache::forgetUnreadNotifications($event->notifiable->id);
            }
        });

        // Step I-2.7: writes that change an org's analytics orphan its cached summaries.
        AnalyticsCache::registerInvalidation();

        // Step I-1.7: named rate limiters (`throttle:ai`, `throttle:exports`, …) — see RateLimits.
        RateLimits::register();

        $this->registerDevQueueWorker();

        // Step 15.1: platform super admins bypass every per-org policy. They never reach the org
        // workspace itself (`org.member` sends them to `/super-admin`), so in practice this grants
        // the platform-level abilities on `OrganizationPolicy`/`SuperAdminReportPolicy`.
        Gate::before(fn (User $user): ?bool => $user->isSuperAdmin() ? true : null);

        Gate::policy(Client::class, ClientPolicy::class);
        Gate::policy(ClientReport::class, ClientReportPolicy::class);
        Gate::policy(ClientInvitation::class, ClientInvitationPolicy::class);
        Gate::policy(BrandBrain::class, BrandBrainPolicy::class);
        Gate::policy(Invoice::class, InvoicePolicy::class);
        Gate::policy(InvoiceItem::class, InvoiceItemPolicy::class);
        Gate::policy(TaxRate::class, TaxRatePolicy::class);
        Gate::policy(SocialAccount::class, SocialAccountPolicy::class);
        Gate::policy(Campaign::class, CampaignPolicy::class);
        Gate::policy(Asset::class, AssetPolicy::class);
        Gate::policy(AiImageGeneration::class, AiImageGenerationPolicy::class);
        Gate::policy(Post::class, PostPolicy::class);
        // Phase 18: Instagram grid plans.
        Gate::policy(GridPlan::class, GridPlanPolicy::class);
        Gate::policy(Organization::class, OrganizationPolicy::class);
        Gate::policy(StaffInvitation::class, StaffInvitationPolicy::class);
        Gate::policy(User::class, StaffPolicy::class);
        Gate::policy(SuperAdminReport::class, SuperAdminReportPolicy::class);
        Gate::policy(OrganizationReport::class, OrganizationReportPolicy::class);
        // Phase M-9: the social inbox.
        Gate::policy(InboxConversation::class, InboxConversationPolicy::class);
        Gate::policy(SavedReply::class, SavedReplyPolicy::class);
    }

    /**
     * `php artisan dev` runs Horizon in place of a queue worker (Horizon swaps its own command in).
     * Horizon needs `ext-pcntl` and only works the `redis` connection, so on the Windows /
     * database-queue dev box it never processed anything — scheduled posts never reached
     * Upload-Post and mail/notifications piled up, with nothing in the log. There, run a plain
     * `queue:listen` on every named queue instead (a bare one would only work `default`).
     */
    protected function registerDevQueueWorker(): void
    {
        if (config('queue.default') === 'redis' && extension_loaded('pcntl')) {
            return;
        }

        DevCommands::except('horizon');
        DevCommands::artisan('queue:listen --queue='.implode(',', Queues::all()).' --tries=1 --timeout=0', 'queue');
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

        // Step I-2.6: an N+1 or a typo'd fillable key fails loudly in dev and tests instead of
        // quietly slowing production down or dropping data.
        Model::preventLazyLoading(! app()->isProduction());
        Model::preventSilentlyDiscardingAttributes(! app()->isProduction());

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
