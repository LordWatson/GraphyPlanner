<?php

namespace App\Actions\AI;

use App\Console\Commands\GenerateDailyIdeasCommand;
use App\Models\AiPostIdea;
use App\Models\Client;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Step B-6.1 — the brand's "Idea of the day" shown on the Today dashboard (B-4.3). Generating ideas
 * costs a vendor call, so the result is cached once per brand per local calendar day: the nightly
 * {@see GenerateDailyIdeasCommand} fills it at ~6am in the brand's timezone,
 * and {@see cached()} only ever reads — the dashboard never triggers a vendor call on page load.
 */
class GenerateDailyIdeaAction
{
    public function __construct(private readonly GenerateBrandIdeasAction $generateIdeas) {}

    /**
     * Generate and cache the brand's idea of the day for today (its local day). Returns the cached
     * payload, generating only on a cache miss (or when `$force`). Null when no idea could be made
     * (AI off, or the provider returned nothing).
     *
     * @return array<string, mixed>|null a `{ai_post_idea_id, title, description, platforms, suggestedCaption}` payload
     */
    public function __invoke(Client $brand, string $timezone, bool $force = false): ?array
    {
        if (! $force && ($existing = $this->cached($brand, $timezone)) !== null) {
            return $existing;
        }

        $ideas = ($this->generateIdeas)($brand);
        $idea = $ideas->ideas[0] ?? null;

        if ($idea === null) {
            Log::info('No idea of the day generated', ['org_id' => $brand->org_id, 'client_id' => $brand->id]);

            return null;
        }

        $persisted = AiPostIdea::query()
            ->where('client_id', $brand->id)
            ->where('title', $idea->title)
            ->first();

        $payload = [
            'ai_post_idea_id' => $persisted?->id,
            'title' => $idea->title,
            'description' => $idea->description,
            'platforms' => $idea->platforms,
            'suggestedCaption' => $idea->suggestedCaption,
        ];

        Cache::put($this->key($brand, $timezone), $payload, $this->ttlSeconds($timezone));

        Log::info('Idea of the day cached', ['org_id' => $brand->org_id, 'client_id' => $brand->id, 'ai_post_idea_id' => $persisted?->id]);

        return $payload;
    }

    /**
     * Read today's cached idea without ever generating one.
     *
     * @return array<string, mixed>|null
     */
    public function cached(Client $brand, string $timezone): ?array
    {
        $value = Cache::get($this->key($brand, $timezone));

        if (! is_array($value)) {
            return null;
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    private function key(Client $brand, string $timezone): string
    {
        $localDate = CarbonImmutable::now($this->safeTimezone($timezone))->toDateString();

        return "business:idea-of-the-day:{$brand->id}:{$localDate}";
    }

    /**
     * Keep the entry until a little after the brand's next local midnight, so "today's" idea rolls
     * over cleanly rather than lingering into tomorrow.
     */
    private function ttlSeconds(string $timezone): int
    {
        $now = CarbonImmutable::now($this->safeTimezone($timezone));
        $expiry = $now->addDay()->startOfDay()->addHours(2);

        return max(3600, (int) $now->diffInSeconds($expiry));
    }

    private function safeTimezone(string $timezone): string
    {
        return in_array($timezone, timezone_identifiers_list(), true) ? $timezone : 'UTC';
    }
}
