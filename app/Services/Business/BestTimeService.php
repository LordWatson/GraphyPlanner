<?php

namespace App\Services\Business;

use App\Enums\ConnectionStatus;
use App\Enums\Platform;
use App\Models\Client;
use App\Models\SocialAccount;
use App\Services\Analytics\BestTimeService as EngagementBestTimeService;
use Illuminate\Support\Collection;

/**
 * Step B-8.3 — "best times to post" for a single business/creator brand. A thin business-shell
 * layer over the shared, engagement-driven {@see EngagementBestTimeService}: for each of the
 * brand's connected accounts it uses the account's **own** learned slot when there's enough
 * published history, and otherwise falls back to a sensible platform default for the account's
 * timezone (`analytics.best_time.defaults`) so a brand that's just getting started still gets a
 * concrete "post around Tue 08:00" to aim at instead of an empty panel.
 *
 * Every hour is **local to the account's timezone** (the dual-timezone rule), never the server's.
 * Consumed by the business Insights page (B-8.1), the "Today" dashboard and the quick-post /
 * weekly-plan slot pickers, which all read the same output.
 */
class BestTimeService
{
    private const WEEKDAYS = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];

    public function __construct(private readonly EngagementBestTimeService $engagement) {}

    /**
     * One suggestion per connected account for the brand, ordered by platform then handle.
     *
     * @return list<array{
     *     social_account_id: int,
     *     platform: string,
     *     platform_label: string,
     *     handle: string,
     *     timezone: string,
     *     source: 'data'|'default',
     *     sample: int,
     *     slot: array{weekday: int, hour: int, label: string},
     *     alternatives: list<array{weekday: int, hour: int, label: string}>,
     * }>
     */
    public function forBrand(Client $brand): array
    {
        /** @var Collection<int, SocialAccount> $accounts */
        $accounts = $brand->socialAccounts()
            ->where('connection_status', ConnectionStatus::Connected)
            ->orderBy('platform')
            ->orderBy('handle')
            ->get(['id', 'platform', 'handle', 'timezone']);

        if ($accounts->isEmpty()) {
            return [];
        }

        $learned = $this->engagement->forAccounts(
            array_values($accounts->pluck('id')->map(fn ($id) => (int) $id)->all()),
        );

        $rows = [];

        foreach ($accounts as $account) {
            $timezone = $this->validTimezone($account->timezone);
            $data = $learned[$account->id] ?? ['status' => 'learning', 'sample' => 0, 'slots' => []];

            $rows[] = [
                'social_account_id' => (int) $account->id,
                'platform' => $account->platform->value,
                'platform_label' => $account->platform->label(),
                'handle' => (string) $account->handle,
                'timezone' => $timezone,
                ...$this->suggestion($account->platform, $timezone, $data),
            ];
        }

        return $rows;
    }

    /**
     * The single suggested slot for one account, learned-or-default. Used by the slot pickers that
     * only need "when should this one account post next".
     *
     * @return array{weekday: int, hour: int, label: string, source: 'data'|'default'}
     */
    public function suggestForAccount(SocialAccount $account): array
    {
        $timezone = $this->validTimezone($account->timezone);
        $data = $this->engagement->forAccounts([(int) $account->id])[$account->id]
            ?? ['status' => 'learning', 'sample' => 0, 'slots' => []];

        $suggestion = $this->suggestion($account->platform, $timezone, $data);

        return [...$suggestion['slot'], 'source' => $suggestion['source']];
    }

    /**
     * The platform's default slot (no history needed), in the given timezone. Exposed so callers
     * without an account (e.g. a brand-new workspace with no accounts yet) can still anchor on a
     * sensible time.
     *
     * @return array{weekday: int, hour: int, label: string}
     */
    public function defaultSlotFor(Platform $platform, string $timezone): array
    {
        $defaults = (array) config('analytics.best_time.defaults', []);
        $slot = $defaults[$platform->value] ?? $defaults['default'] ?? null;
        $slot = is_array($slot) ? array_values($slot) : [];

        $weekday = max(1, min(7, (int) ($slot[0] ?? 3)));
        $hour = max(0, min(23, (int) ($slot[1] ?? 10)));

        return $this->slot($weekday, $hour);
    }

    /**
     * Pick the learned top slot when the account is `ready`, otherwise the platform default.
     *
     * @param  array{status: string, sample: int, slots: list<array{weekday: int, hour: int, label?: string}>}  $data
     * @return array{source: 'data'|'default', sample: int, slot: array{weekday: int, hour: int, label: string}, alternatives: list<array{weekday: int, hour: int, label: string}>}
     */
    private function suggestion(Platform $platform, string $timezone, array $data): array
    {
        if ($data['status'] === 'ready' && $data['slots'] !== []) {
            $slots = array_map(fn (array $slot) => $this->slot((int) $slot['weekday'], (int) $slot['hour']), $data['slots']);

            return [
                'source' => 'data',
                'sample' => (int) $data['sample'],
                'slot' => $slots[0],
                'alternatives' => array_slice($slots, 1),
            ];
        }

        return [
            'source' => 'default',
            'sample' => (int) $data['sample'],
            'slot' => $this->defaultSlotFor($platform, $timezone),
            'alternatives' => [],
        ];
    }

    /**
     * @return array{weekday: int, hour: int, label: string}
     */
    private function slot(int $weekday, int $hour): array
    {
        return [
            'weekday' => $weekday,
            'hour' => $hour,
            'label' => sprintf('%s %02d:00', self::WEEKDAYS[$weekday] ?? 'Mon', $hour),
        ];
    }

    private function validTimezone(?string $timezone): string
    {
        return $timezone !== null && in_array($timezone, timezone_identifiers_list(), true) ? $timezone : 'UTC';
    }
}
