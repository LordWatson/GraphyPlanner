<?php

namespace App\Actions\AI;

use App\Contracts\LocalIdeaProvider;
use App\Enums\PostStatus;
use App\Models\AiPostIdea as PlanItem;
use App\Models\Client;
use App\Models\PostTarget;
use App\Services\AI\AiGuardrailService;
use App\Services\AI\AiTextUsage;
use App\Services\AI\DeepSeekProvider;
use App\Services\AI\IdeaContextBuilder;
use App\Services\Analytics\BestTimeService;
use App\Services\Business\PostingGoalGapService;
use App\Support\AI\AiPostIdeas;
use App\Support\AI\IdeaContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Step B-6.3 — "Plan my week". Fills the posting goal's gaps across the next 7 days with draft plan
 * items for the brand to accept, edit or discard — nothing is scheduled without confirmation.
 *
 * A plan item is an `ai_post_ideas` row marked with the local day it's planned for (`planned_for`),
 * carrying a best-time slot (`suggested_post_at`) and AI-written content grounded in the week's
 * local/seasonal context. Accepting an item turns it into a draft `Idea` post via
 * {@see RecommendPostsAction::persist()}; the slot guides the user when they open it to schedule.
 *
 * Idempotent: a day already covered by a scheduled post or an un-accepted plan item is skipped, so
 * running it twice never duplicates. One transaction; the AI call is guardrailed and metered.
 */
class GenerateWeeklyPlanAction
{
    private const WINDOW_DAYS = 7;

    public function __construct(
        private readonly LocalIdeaProvider $provider,
        private readonly IdeaContextBuilder $contextBuilder,
        private readonly AiGuardrailService $guardrail,
        private readonly PostingGoalGapService $gapService,
        private readonly BestTimeService $bestTime,
        private readonly AiTextUsage $usage,
    ) {}

    /**
     * Generate (or top up) the week's plan and return its current, un-accepted items.
     *
     * @return list<array<string, mixed>>
     */
    public function __invoke(Client $brand): array
    {
        $goal = $brand->postingGoal();
        $timezone = $this->timezone($brand);
        $now = Carbon::now();

        if (! $goal->isSet()) {
            return $this->currentPlan($brand);
        }

        $gaps = $this->gapService->gaps($goal, $timezone, $now, self::WINDOW_DAYS, $this->coveredDates($brand, $timezone, $now))['days'];

        if ($gaps === []) {
            Log::info('Weekly plan: no gaps to fill', ['org_id' => $brand->org_id, 'client_id' => $brand->id]);

            return $this->currentPlan($brand);
        }

        $context = $this->contextBuilder->build($brand);
        $ideas = $this->generateIdeas($brand, $context);
        $slots = $this->bestHours($brand, $goal->preferredDays);
        $platforms = $this->platforms($brand);

        DB::transaction(function () use ($brand, $gaps, $ideas, $context, $slots, $platforms, $timezone): void {
            foreach ($gaps as $index => $gap) {
                $item = $this->itemContent($index, $gap, $ideas, $context);
                $hour = $slots[$gap['weekday']] ?? (int) config('ai.plan.default_hour', 9);
                $slotUtc = CarbonImmutable::parse($gap['date'].' '.sprintf('%02d:00:00', $hour), $timezone)->utc();

                PlanItem::create([
                    'org_id' => $brand->org_id,
                    'client_id' => $brand->id,
                    // The date keeps the title unique per day (ai_post_ideas is unique on client+title).
                    'title' => $item['title'].' — '.$gap['label'],
                    'description' => $item['description'],
                    // The brand's connected platforms, or the idea's suggestion when none are connected yet.
                    'platforms' => $platforms !== [] ? $platforms : $item['platforms'],
                    'suggested_caption' => $item['caption'],
                    'suggested_post_at' => $slotUtc->toIso8601String(),
                    'planned_for' => $gap['date'],
                ]);
            }
        });

        Log::info('Weekly plan generated', ['org_id' => $brand->org_id, 'client_id' => $brand->id, 'items' => count($gaps)]);

        return $this->currentPlan($brand);
    }

    /**
     * The brand's current, un-accepted plan items, soonest first.
     *
     * @return list<array<string, mixed>>
     */
    public function currentPlan(Client $brand): array
    {
        $timezone = $this->timezone($brand);

        return array_values($brand->aiPostIdeas()
            ->whereNotNull('planned_for')
            ->whereNull('post_id')
            ->orderBy('planned_for')
            ->orderBy('id')
            ->get()
            ->map(fn (PlanItem $item) => [
                'id' => $item->id,
                'title' => $item->title,
                'description' => $item->description,
                'platforms' => $item->platforms ?? [],
                'suggestedCaption' => $item->suggested_caption,
                // A brand-local label ("Mon 3 Nov · 09:00") so the page needn't know the timezone.
                'slotLabel' => $this->slotLabel($item->suggested_post_at, $timezone),
                'plannedFor' => $item->planned_for?->toDateString(),
            ])
            ->all());
    }

    private function slotLabel(?string $suggestedPostAt, string $timezone): ?string
    {
        if ($suggestedPostAt === null) {
            return null;
        }

        return CarbonImmutable::parse($suggestedPostAt)->setTimezone($timezone)->isoFormat('ddd D MMM · HH:mm');
    }

    private function generateIdeas(Client $brand, IdeaContext $context): AiPostIdeas
    {
        $startedAt = microtime(true);
        $ideas = $this->provider->recommendLocalPosts($brand, $context);

        $deepSeek = $this->provider instanceof DeepSeekProvider;
        $this->usage->record(
            $brand,
            'generateWeeklyPlan',
            $ideas->ideas !== [],
            $startedAt,
            $deepSeek ? 'deepseek' : 'null',
            $deepSeek ? (string) config('ai.deepseek.model', 'deepseek-chat') : 'none',
            $deepSeek ? (float) config('ai.deepseek.estimated_cost_per_call', 0) : 0.0,
        );

        return $this->guardrail->filterPostIdeas($brand->brandBrain, $ideas, ['org_id' => $brand->org_id, 'client_id' => $brand->id]);
    }

    /**
     * The content for one gap day: a generated idea when there is one (round-robin over the few the
     * model returned), otherwise a light title from an upcoming local event, a content pillar, or a
     * plain fallback — so the plan is useful even with AI off.
     *
     * @param  array{date: string, weekday: int, label: string}  $gap
     * @return array{title: string, description: string|null, caption: string|null, platforms: list<string>}
     */
    private function itemContent(int $index, array $gap, AiPostIdeas $ideas, IdeaContext $context): array
    {
        if ($ideas->ideas !== []) {
            $idea = $ideas->ideas[$index % count($ideas->ideas)];

            return ['title' => $idea->title, 'description' => $idea->description, 'caption' => $idea->suggestedCaption, 'platforms' => array_values($idea->platforms)];
        }

        foreach ($context->events as $event) {
            if ($event->date === $gap['date']) {
                return ['title' => $event->name, 'description' => 'Tie a post to '.$event->name.'.', 'caption' => null, 'platforms' => []];
            }
        }

        if ($context->pillars !== []) {
            $pillar = $context->pillars[$index % count($context->pillars)];

            return ['title' => $pillar, 'description' => 'A '.strtolower($pillar).' post.', 'caption' => null, 'platforms' => []];
        }

        return ['title' => 'Post idea', 'description' => null, 'caption' => null, 'platforms' => []];
    }

    /**
     * Best posting hour per weekday from the brand's own engagement data where there's enough of it;
     * weekdays without a data-backed slot fall back to the config default at the call site.
     *
     * @param  list<int>  $preferredDays
     * @return array<int, int> weekday (ISO) => hour
     */
    private function bestHours(Client $brand, array $preferredDays): array
    {
        $accountIds = array_values($brand->socialAccounts()->pluck('id')->map(fn ($id) => (int) $id)->all());

        if ($accountIds === []) {
            return [];
        }

        $hours = [];

        foreach ($this->bestTime->forAccounts($accountIds) as $result) {
            foreach ($result['slots'] as $slot) {
                // Keep the first (highest-ranked) slot seen for each weekday.
                $hours[$slot['weekday']] ??= $slot['hour'];
            }
        }

        return $hours;
    }

    /**
     * @return list<string>
     */
    private function platforms(Client $brand): array
    {
        return array_values(array_unique($brand->socialAccounts()
            ->get(['platform'])
            ->map(fn ($account) => $account->platform->value)
            ->all()));
    }

    /**
     * Local dates in the window the goal should treat as already covered: a scheduled/published post,
     * or an un-accepted plan item already sitting on that day (so a re-run tops up, never duplicates).
     *
     * @return list<string>
     */
    private function coveredDates(Client $brand, string $timezone, Carbon $now): array
    {
        $windowEnd = $now->clone()->setTimezone($timezone)->startOfDay()->addDays(self::WINDOW_DAYS)->utc();

        $scheduled = PostTarget::query()
            ->whereNotNull('scheduled_at_utc')
            ->where('scheduled_at_utc', '>=', $now)
            ->where('scheduled_at_utc', '<', $windowEnd)
            ->whereHas('post', fn (Builder $post) => $post->where('org_id', $brand->org_id)
                ->where('client_id', $brand->id)
                ->whereIn('status', [PostStatus::Scheduled, PostStatus::Published, PostStatus::PartiallyPublished]))
            ->pluck('scheduled_at_utc')
            ->map(fn ($utc) => $utc->clone()->setTimezone($timezone)->toDateString())
            ->all();

        $planned = $brand->aiPostIdeas()
            ->whereNotNull('planned_for')
            ->whereNull('post_id')
            ->pluck('planned_for')
            ->map(fn ($date) => $date->toDateString())
            ->all();

        return array_values(array_unique([...$scheduled, ...$planned]));
    }

    private function timezone(Client $brand): string
    {
        $timezone = $brand->organization->default_timezone ?? config('app.timezone', 'UTC');

        return in_array($timezone, timezone_identifiers_list(), true) ? $timezone : 'UTC';
    }
}
