<?php

namespace App\Actions\Grids;

use App\Actions\Posts\UpdatePostAction;
use App\Enums\PostStatus;
use App\Models\GridPlan;
use App\Models\GridTile;
use App\Models\PostTarget;
use App\Models\User;
use App\Services\Grids\GridPlanChanges;
use App\Services\Grids\GridPlanService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class PlanGridScheduleAction
{
    public const DAILY = 'daily';

    public const WEEKDAYS = 'weekdays';

    public const EVERY_N_DAYS = 'every_n_days';

    public const CADENCES = [self::DAILY, self::WEEKDAYS, self::EVERY_N_DAYS];

    /** Posts in these states have gone (or are going) out — their time is history. */
    private const LOCKED = [PostStatus::Published, PostStatus::PartiallyPublished, PostStatus::Archived];

    public function __construct(
        private readonly UpdatePostAction $updatePost,
        private readonly GridPlanService $grids = new GridPlanService,
        private readonly GridPlanChanges $changes = new GridPlanChanges,
    ) {}

    /**
     * Step 18.10 — the dates a cadence gives each tile, in **publish order** (the last position
     * goes out first), in the account's own timezone. Writes nothing.
     *
     * @param  array{start_date: string, time: string, cadence: string, weekdays?: list<int>|null, every_n_days?: int|null}  $input
     * @return list<array{tile_id: int, position: int, sequence: int, date: string, time: string, publish_at: string, post_id: int|null, locked: bool}>
     */
    public function preview(GridPlan $plan, array $input): array
    {
        $this->grids->load($plan);
        $timezone = $plan->socialAccount->timezone ?? 'UTC';
        $publishOrder = $plan->tiles->sortByDesc('position')->values();

        $dates = $this->dates($input, $publishOrder->count());
        $rows = [];

        foreach ($publishOrder as $index => $tile) {
            $date = $dates[$index];
            $local = CarbonImmutable::parse($date.' '.$input['time'], $timezone);

            $rows[] = [
                'tile_id' => $tile->id,
                'position' => $tile->position,
                'sequence' => $index + 1,
                'date' => $date,
                'time' => $input['time'],
                'publish_at' => $local->utc()->toIso8601String(),
                'post_id' => $tile->post_id,
                'locked' => $tile->post !== null && in_array($tile->post->status, self::LOCKED, true),
            ];
        }

        return $rows;
    }

    /**
     * Apply the cadence: each tile's planned date, and each linked post's Instagram time through
     * `UpdatePostAction` (so channels the vendor already holds are recalled and re-sent at the new
     * time). Posts that have already gone out keep their time and are reported.
     *
     * @param  array{start_date: string, time: string, cadence: string, weekdays?: list<int>|null, every_n_days?: int|null}  $input
     * @return array{tiles: int, posts: list<int>, skipped: list<array{tile_id: int, position: int, reason: string}>}
     */
    public function apply(GridPlan $plan, User $user, array $input): array
    {
        $rows = $this->preview($plan, $input);
        $skipped = [];
        $posts = [];

        DB::transaction(function () use ($plan, $user, $rows, &$skipped, &$posts) {
            $locked = $this->changes->lock($plan);
            $tiles = GridTile::query()->where('grid_plan_id', $locked->id)->with('post.targets')->get()->keyBy('id');

            foreach ($rows as $row) {
                /** @var GridTile $tile */
                $tile = $tiles->get($row['tile_id']);

                if ($row['locked']) {
                    $skipped[] = ['tile_id' => $tile->id, 'position' => $tile->position, 'reason' => 'It has already gone out.'];

                    continue;
                }

                $tile->update(['planned_local_date' => $row['date'], 'planned_local_time' => $row['time']]);

                if ($tile->post === null) {
                    continue;
                }

                $targets = $tile->post->targets->map(fn (PostTarget $target) => [
                    'social_account_id' => $target->social_account_id,
                    'scheduled_local_date' => $target->social_account_id === $locked->social_account_id ? $row['date'] : $target->scheduled_local_date?->toDateString(),
                    'scheduled_local_time' => $target->social_account_id === $locked->social_account_id ? $row['time'] : $target->scheduled_local_time,
                ])->values()->all();

                try {
                    ($this->updatePost)($tile->post, ['targets' => $targets]);
                    $posts[] = $tile->post->id;
                } catch (ValidationException $e) {
                    $skipped[] = ['tile_id' => $tile->id, 'position' => $tile->position, 'reason' => (string) (collect($e->errors())->flatten()->first() ?? 'Its time couldn’t change.')];
                }
            }

            $this->changes->record($locked, $user, 'schedule_planned', affectsLook: false);
        });

        Log::info('Grid schedule applied', [
            'grid_plan_id' => $plan->id,
            'org_id' => $plan->org_id,
            'user_id' => $user->id,
            'tiles' => count($rows),
            'posts' => count($posts),
            'skipped' => count($skipped),
        ]);

        return ['tiles' => count($rows) - count($skipped), 'posts' => $posts, 'skipped' => $skipped];
    }

    /**
     * Local dates for `$count` tiles from the start date along the cadence.
     *
     * @param  array{start_date: string, time: string, cadence: string, weekdays?: list<int>|null, every_n_days?: int|null}  $input
     * @return list<string>
     */
    private function dates(array $input, int $count): array
    {
        $day = CarbonImmutable::parse($input['start_date'])->startOfDay();
        $cadence = $input['cadence'];
        $weekdays = array_values(array_unique(array_map('intval', (array) ($input['weekdays'] ?? []))));
        $step = max(1, (int) ($input['every_n_days'] ?? 1));

        if ($cadence === self::WEEKDAYS && $weekdays === []) {
            throw ValidationException::withMessages(['weekdays' => __('Pick at least one day of the week.')]);
        }

        $dates = [];

        while (count($dates) < $count) {
            if ($cadence !== self::WEEKDAYS || in_array($day->dayOfWeekIso, $weekdays, true)) {
                $dates[] = $day->toDateString();
            }

            $day = $day->addDays($cadence === self::EVERY_N_DAYS ? $step : 1);
        }

        return $dates;
    }
}
