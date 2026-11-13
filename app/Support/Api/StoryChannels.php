<?php

namespace App\Support\Api;

use App\Http\Requests\Concerns\ValidatesStoryExtras;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Support\Publishing\StoryExtras;
use App\Support\Stories\StoryChannelPresenter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Step S-5.4 — Story extras on a channel, for callers that are code rather than the editor: the
 * public API and the MCP tools. One place, so the two accept, refuse and report exactly the same
 * thing — and the same thing the editor does, because the rules are `ValidatesStoryExtras`.
 *
 * Nothing here publishes or confirms anything. A channel with extras is posted by a person
 * (`story-extras.md` §1.3); this only stores what they will add and says so plainly.
 */
final class StoryChannels
{
    use ValidatesStoryExtras;

    /** Per-channel keys only `UpdatePostAction` stores (`CreatePostAction` knows dates alone). */
    private const OPTIONS = ['format', 'story_extras', 'handoff_recipient'];

    /**
     * What `present()` reads, relative to a post. Eager-load wherever channels are listed.
     *
     * @var list<string>
     */
    public const RELATIONS = ['targets.openStoryHandoff', 'targets.postedStoryHandoff'];

    /**
     * Validate `$input` against `$rules` plus the editor's own rules for each channel's `format`,
     * `story_extras` and `handoff_recipient` under `$key` (`channels` for the API, `targets` for
     * MCP). Returns the validated data.
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public static function validate(array $input, string $key, array $rules): array
    {
        $self = new self;
        $input = self::withoutBlankExtras($input, $key);

        $validated = Validator::make($input, [
            ...$rules,
            "{$key}.*.format" => ['nullable', 'string', 'max:32'],
            ...$self->storyExtrasRules("{$key}.*"),
        ], $self->storyExtrasMessages("{$key}.*"))
            ->after(function ($validator) use ($self, $input, $key): void {
                $targets = $input[$key] ?? null;

                if (! is_array($targets) || $validator->errors()->isNotEmpty()) {
                    return;
                }

                $accounts = SocialAccount::query()
                    ->whereIn('id', collect($targets)->pluck('social_account_id')->filter()->all())
                    ->get()
                    ->keyBy('id');

                foreach ($targets as $i => $target) {
                    $account = $accounts->get((int) ($target['social_account_id'] ?? 0));
                    $format = $target['format'] ?? null;

                    if (filled($format) && $account !== null && ! $account->platform->spec()->hasFormat((string) $format)) {
                        $validator->errors()->add("{$key}.{$i}.format", __(':platform doesn’t offer that format.', ['platform' => $account->platform->label()]));
                    }
                }

                $self->validateStoryExtrasPlatforms($validator, $targets, $key);
            })
            ->validate();

        // Laravel leaves an `array` key with child rules out of the validated data when no child
        // was sent, so `story_extras: {}` ("clear them") would read as "not sent". Put back what
        // was sent: the rules have bounded it, and `StoryExtras::fromArray()` drops unknown keys.
        // (`ValidatesStoryExtras::restoreStoryExtras()` does this for the editor's `targets`.)
        foreach (is_array($validated[$key] ?? null) ? $validated[$key] : [] as $i => $target) {
            $raw = $input[$key][$i] ?? null;

            if (is_array($target) && is_array($raw) && array_key_exists('story_extras', $raw)) {
                $validated[$key][$i]['story_extras'] = is_array($raw['story_extras']) ? $raw['story_extras'] : null;
            }
        }

        return $validated;
    }

    /**
     * To the editor a key that is present but blank means "added, not filled in yet"
     * (`StoryExtras::fromArray()`). To a caller writing JSON, `"hashtag": null` means "no hashtag".
     * So blanks are dropped before anything reads the extras: a null, an empty string, an empty
     * list, and a `link` or `poll` with nothing in it.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function withoutBlankExtras(array $input, string $key): array
    {
        if (! is_array($input[$key] ?? null)) {
            return $input;
        }

        $blank = fn (mixed $value): bool => $value === null || $value === [] || (is_string($value) && trim($value) === '');

        foreach ($input[$key] as $i => $target) {
            if (! is_array($target) || ! is_array($target['story_extras'] ?? null)) {
                continue;
            }

            $extras = $target['story_extras'];

            foreach (['link', 'poll'] as $group) {
                if (is_array($extras[$group] ?? null)) {
                    $extras[$group] = array_filter($extras[$group], fn (mixed $value): bool => ! $blank($value));
                }
            }

            $input[$key][$i]['story_extras'] = array_filter($extras, fn (mixed $value): bool => ! $blank($value));
        }

        return $input;
    }

    /**
     * MCP only. "If nobody posts it, publish it plain" (`if_missed`) is a person's standing
     * instruction on one post (`story-extras.md` §1.3), so an assistant can never set it: whatever
     * it sends is dropped, and a choice a person already made on that channel is carried through
     * an update of the channel's other extras. Clearing the extras (`story_extras: null`) still
     * clears everything.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function keepIfMissed(array $input, string $key, ?Post $post = null): array
    {
        if (! is_array($input[$key] ?? null)) {
            return $input;
        }

        $stored = $post?->targets()->get()->keyBy('social_account_id');

        foreach ($input[$key] as $i => $target) {
            if (! is_array($target) || ! is_array($target['story_extras'] ?? null)) {
                continue;
            }

            unset($input[$key][$i]['story_extras']['if_missed']);

            /** @var PostTarget|null $existing */
            $existing = $stored?->get((int) ($target['social_account_id'] ?? 0));

            if ($existing?->storyExtras()->publishesPlainIfMissed() === true) {
                $input[$key][$i]['story_extras']['if_missed'] = StoryExtras::IF_MISSED_PUBLISH_PLAIN;
            }
        }

        return $input;
    }

    /**
     * Whether any channel sets something `CreatePostAction` does not store, so a create has to
     * follow up through `UpdatePostAction` (the path `SyncTargetStoryExtrasAction` runs on).
     *
     * @param  array<int|string, mixed>  $targets
     */
    public static function carryOptions(array $targets): bool
    {
        return collect($targets)->contains(
            fn (mixed $target): bool => is_array($target) && array_intersect_key($target, array_flip(self::OPTIONS)) !== [],
        );
    }

    /**
     * The assisted-lane fields of one channel. Never a handoff token or a `/story/` link: those
     * open the handoff without signing in, and belong only in the poster's own notification.
     *
     * @return array{story_extras: array<string, mixed>|null, handoff_recipient: string|null, posted_by_hand: bool, handoff_state: string|null}
     */
    public static function present(PostTarget $target): array
    {
        // No-ops when the caller eager-loaded (`RELATIONS`) and set `post`; lazy loading is off.
        $target->loadMissing(['post', ...PostTarget::HANDOFF_STATE_RELATIONS]);

        return [
            'story_extras' => $target->storyExtras()->toArray(),
            'handoff_recipient' => $target->handoff_recipient?->value,
            'posted_by_hand' => $target->handoff_required,
            'handoff_state' => StoryChannelPresenter::state($target)['state'] ?? null,
        ];
    }

    /**
     * What a caller must be told about a post with a channel that is posted by hand, so nobody
     * (and no AI agent) reports that the whole post will publish itself. Null when every channel
     * publishes automatically.
     */
    public static function note(Post $post): ?string
    {
        $assisted = $post->targets()
            ->where('handoff_required', true)
            ->with('socialAccount:id,platform,timezone')
            ->get();

        /** @var PostTarget|null $first */
        $first = $assisted->first();

        if ($first === null) {
            return null;
        }

        $network = $first->socialAccount?->platform->label() ?? 'Instagram';
        $rest = $post->targets()->count() > $assisted->count()
            ? 'Every other channel publishes automatically.'
            : 'Nothing on this post publishes automatically.';

        if ($assisted->count() > 1) {
            return "{$assisted->count()} channels are stories on {$network} with a link or stickers. {$network} only allows those from its own app, so Alima will not publish them: someone posts each one by hand from the {$network} app when it is due, and is reminded at that time. {$rest}";
        }

        $when = $first->scheduled_local_date !== null && $first->scheduled_local_time !== null
            ? sprintf('at %s on %s (%s)', $first->scheduled_local_time, $first->scheduled_local_date->toDateString(), $first->socialAccount->timezone ?? 'UTC')
            : 'when it is due';

        return "1 channel is a story on {$network} with a link or stickers. {$network} only allows those from its own app, so Alima will not publish it: someone posts it by hand from the {$network} app {$when}, and is reminded at that time. {$rest}";
    }
}
