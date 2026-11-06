<?php

namespace App\Actions\Store;

use App\Actions\AI\RewriteCaptionAction;
use App\Actions\Campaigns\CreateCampaignAction;
use App\Actions\Posts\CreatePostAction;
use App\Enums\CampaignObjective;
use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\WritingTool;
use App\Models\Campaign;
use App\Models\Product;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Analytics\BestTimeService;
use App\Support\MoneyFormatter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Step CG-4.7 — the differentiator: turn a product into a launch plan. One collection (campaign)
 * holding four **draft** posts — teaser, launch day, reminder, social proof — each with a caption in
 * the brand's own voice, a suggested slot from the brand's best posting times, and the product link.
 *
 * Principle 5 (AI suggests, people decide) is the whole shape of this:
 *
 *  - every post is created as a `draft`, so nothing can publish on its own. The suggested date and
 *    time sit on each target for the owner to accept or move when they schedule it;
 *  - the captions are written by the shared {@see RewriteCaptionAction}, which means the brand-voice
 *    rewrite is already budget-checked, metered on `ai_usage_logs`, and run through the never-say /
 *    banned-phrase guardrail with a fall back to our own plain seed copy. Nothing new to maintain
 *    here, and no second AI path to keep honest;
 *  - with AI off (or a failing provider) the seeds ship as they are, so the launch plan still
 *    arrives — just without the voice.
 */
class DraftLaunchCampaignAction
{
    /**
     * The beats of a launch, as days relative to launch day.
     *
     * @var list<array{key: string, day: int, seed: string}>
     */
    private const BEATS = [
        ['key' => 'teaser', 'day' => -3, 'seed' => 'Something new is coming: %s. Launching %s.'],
        ['key' => 'launch', 'day' => 0, 'seed' => '%s is live now — %s. Link in bio: %s'],
        ['key' => 'reminder', 'day' => 3, 'seed' => 'Still here, still worth it: %s (%s). Grab it: %s'],
        ['key' => 'proof', 'day' => 7, 'seed' => 'What people are saying about %s. Have a look: %s'],
    ];

    public function __construct(
        private readonly CreateCampaignAction $createCampaign,
        private readonly CreatePostAction $createPost,
        private readonly RewriteCaptionAction $rewrite,
        private readonly BestTimeService $bestTime,
    ) {}

    public function __invoke(Product $product, ?User $actor = null, ?CarbonImmutable $launchOn = null): Campaign
    {
        $brand = $product->client;
        $accounts = SocialAccount::query()->withoutOrgScope()->where('client_id', $brand->id)->get();
        $launchOn = ($launchOn ?? CarbonImmutable::now()->addDays(3))->startOfDay();
        $hours = $this->bestHours(array_values($accounts->pluck('id')->map(fn ($id) => (int) $id)->all()));

        $captions = [];

        foreach (self::BEATS as $beat) {
            $captions[$beat['key']] = $this->caption($product, $beat, $launchOn, $accounts->first()?->platform);
        }

        $campaign = DB::transaction(function () use ($product, $brand, $actor, $launchOn, $accounts, $captions, $hours): Campaign {
            $campaign = ($this->createCampaign)($brand, [
                'name' => 'Launch: '.$product->title,
                'objective' => CampaignObjective::Launch,
                'default_link_url' => $product->publicUrl(),
                'start_date' => $launchOn->addDays(self::BEATS[0]['day'])->toDateString(),
                'end_date' => $launchOn->addDays(self::BEATS[count(self::BEATS) - 1]['day'])->toDateString(),
            ], $actor);

            foreach (self::BEATS as $beat) {
                $date = $launchOn->addDays($beat['day']);

                ($this->createPost)($brand, [
                    'campaign_id' => $campaign->id,
                    'created_by' => $actor?->id,
                    // A draft, never scheduled: the slot below is a suggestion the owner confirms.
                    'status' => PostStatus::Draft,
                    'master_caption' => $captions[$beat['key']],
                    'link_url' => $product->publicUrl(),
                    'targets' => $accounts->map(fn (SocialAccount $account) => [
                        'social_account_id' => $account->id,
                        'scheduled_local_date' => $date->toDateString(),
                        'scheduled_local_time' => sprintf('%02d:00', $hours[$date->dayOfWeekIso] ?? (int) config('ai.plan.default_hour', 9)),
                    ])->all(),
                ]);
            }

            return $campaign;
        });

        Log::info('Launch campaign drafted', [
            'org_id' => $product->org_id,
            'client_id' => $brand->id,
            'product_id' => $product->id,
            'campaign_id' => $campaign->id,
            'posts' => count(self::BEATS),
            'user_id' => $actor?->id,
        ]);

        return $campaign;
    }

    /**
     * One beat's caption: our own seed copy, then the brand-voice rewrite. A provider failure (or no
     * AI at all) leaves the seed, so the launch plan is never blocked on the model.
     *
     * @param  array{key: string, day: int, seed: string}  $beat
     */
    private function caption(Product $product, array $beat, CarbonImmutable $launchOn, ?Platform $platform): string
    {
        $seed = $this->seed($product, $beat, $launchOn);

        try {
            return ($this->rewrite)($product->client, $seed, WritingTool::Adapt, $platform);
        } catch (Throwable $e) {
            Log::warning('Launch caption rewrite skipped', [
                'product_id' => $product->id,
                'beat' => $beat['key'],
                'exception' => $e::class,
            ]);

            return $seed;
        }
    }

    /**
     * @param  array{key: string, day: int, seed: string}  $beat
     */
    private function seed(Product $product, array $beat, CarbonImmutable $launchOn): string
    {
        $price = MoneyFormatter::format($product->price_minor / 100, $product->currency);
        $link = $product->publicUrl() ?? '';

        return trim(match ($beat['key']) {
            'teaser' => sprintf($beat['seed'], $product->title, $launchOn->isoFormat('dddd D MMMM')),
            'launch' => sprintf($beat['seed'], $product->title, $price, $link),
            'reminder' => sprintf($beat['seed'], $product->title, $price, $link),
            default => sprintf($beat['seed'], $product->title, $link),
        });
    }

    /**
     * Best posting hour per weekday from the brand's own data, exactly as "Plan my week" uses it.
     *
     * @param  list<int>  $accountIds
     * @return array<int, int>
     */
    private function bestHours(array $accountIds): array
    {
        $hours = [];

        foreach ($this->bestTime->forAccounts($accountIds) as $result) {
            foreach ($result['slots'] as $slot) {
                $hours[$slot['weekday']] ??= $slot['hour'];
            }
        }

        return $hours;
    }
}
