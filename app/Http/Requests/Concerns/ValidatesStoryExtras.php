<?php

namespace App\Http\Requests\Concerns;

use App\Enums\StoryHandoffRecipient;
use App\Models\SocialAccount;
use App\Services\Publishing\PlatformCapabilities;
use App\Support\Publishing\StoryExtras;
use Closure;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Step S-1.3 — the rules for a channel's Story extras (`story-extras.md` §4.2), shared by the
 * editor's save, the public API and the MCP tools so all three accept exactly the same thing.
 *
 * These rules bound the *shape*: types, lengths and counts, and a link that is `https`. Whether the
 * extras are *complete* (a link sticker with a link, a poll with two options) is the blocking
 * `story_extras` checklist item's job, because the editor autosaves half-typed work and a draft is
 * allowed to be unfinished.
 */
trait ValidatesStoryExtras
{
    /**
     * Rules for one channel's `story_extras` and `handoff_recipient`, under `$prefix`
     * (`targets.*` for the editor's form).
     *
     * @return array<string, array<int, mixed>>
     */
    protected function storyExtrasRules(string $prefix): array
    {
        $extras = "{$prefix}.story_extras";

        return [
            "{$prefix}.handoff_recipient" => ['nullable', Rule::enum(StoryHandoffRecipient::class)],
            $extras => ['nullable', 'array'],
            "{$extras}.link" => ['nullable', 'array'],
            "{$extras}.link.url" => ['nullable', 'string', 'max:'.StoryExtras::MAX_URL, self::httpsLinkRule()],
            "{$extras}.link.text" => ['nullable', 'string', 'max:'.StoryExtras::MAX_LINK_TEXT],
            "{$extras}.mentions" => ['nullable', 'array', 'max:'.StoryExtras::MAX_MENTIONS],
            "{$extras}.mentions.*" => ['nullable', 'string', 'regex:/^@?[A-Za-z0-9._]{1,'.StoryExtras::MAX_HANDLE.'}$/'],
            "{$extras}.hashtag" => ['nullable', 'string', 'max:'.StoryExtras::MAX_HASHTAG],
            "{$extras}.location" => ['nullable', 'string', 'max:'.StoryExtras::MAX_LOCATION],
            "{$extras}.poll" => ['nullable', 'array'],
            "{$extras}.poll.question" => ['nullable', 'string', 'max:'.StoryExtras::MAX_POLL_QUESTION],
            "{$extras}.poll.options" => ['nullable', 'array', 'max:'.StoryExtras::MAX_POLL_OPTIONS],
            "{$extras}.poll.options.*" => ['nullable', 'string', 'max:'.StoryExtras::MAX_POLL_OPTION],
            "{$extras}.placement_note" => ['nullable', 'string', 'max:'.StoryExtras::MAX_PLACEMENT_NOTE],
            "{$extras}.if_missed" => ['nullable', Rule::in([StoryExtras::IF_MISSED_FAIL, StoryExtras::IF_MISSED_PUBLISH_PLAIN])],
        ];
    }

    /**
     * Plain-language messages for the rules above (the defaults would read "targets.0.story_extras…").
     *
     * @return array<string, string>
     */
    protected function storyExtrasMessages(string $prefix): array
    {
        $extras = "{$prefix}.story_extras";

        return [
            "{$extras}.link.text.max" => __('Keep the sticker text to :max characters.', ['max' => StoryExtras::MAX_LINK_TEXT]),
            "{$extras}.mentions.max" => __('A story can mention up to :max accounts.', ['max' => StoryExtras::MAX_MENTIONS]),
            "{$extras}.mentions.*.regex" => __('Enter an Instagram handle: letters, numbers, dots and underscores.'),
            "{$extras}.location.max" => __('Keep the location to :max characters.', ['max' => StoryExtras::MAX_LOCATION]),
            "{$extras}.poll.question.max" => __('Keep the poll question to :max characters.', ['max' => StoryExtras::MAX_POLL_QUESTION]),
            "{$extras}.poll.options.max" => __('A poll can have up to :max options.', ['max' => StoryExtras::MAX_POLL_OPTIONS]),
            "{$extras}.poll.options.*.max" => __('Keep each poll option to :max characters.', ['max' => StoryExtras::MAX_POLL_OPTION]),
            "{$extras}.placement_note.max" => __('Keep the placement note to :max characters.', ['max' => StoryExtras::MAX_PLACEMENT_NOTE]),
        ];
    }

    /**
     * The editor posts a real HTML form, so a channel's extras arrive as one JSON string per
     * channel (an empty mentions list has no other form encoding). Decode it before the rules run;
     * anything that is not a JSON object is left as it is, and fails the `array` rule.
     *
     * @param  array<int|string, mixed>  $targets
     * @return array<int|string, mixed>
     */
    protected function decodeStoryExtras(array $targets): array
    {
        foreach ($targets as $i => $target) {
            if (! is_array($target) || ! is_string($target['story_extras'] ?? null)) {
                continue;
            }

            $decoded = json_decode($target['story_extras'], true);

            if (is_array($decoded)) {
                $targets[$i]['story_extras'] = $decoded;
            }
        }

        return $targets;
    }

    /**
     * Put each channel's `story_extras` back into the validated data exactly as it was sent.
     *
     * Laravel leaves an `array` key out of `validated()` when it has child rules and none of the
     * children were sent, so `story_extras: {}` (clear them) and `link: {}` (added, not filled in
     * yet) would both read as "not sent" and silently change nothing. The rules above have
     * already bounded everything that *is* present, and `StoryExtras::fromArray()` drops any key
     * it does not know, so the decoded input is safe to hand on.
     *
     * @param  array<string, mixed>  $validated
     * @param  mixed  $rawTargets  the request's (decoded) `targets` input
     * @return array<string, mixed>
     */
    protected function restoreStoryExtras(array $validated, mixed $rawTargets): array
    {
        if (! is_array($rawTargets) || ! is_array($validated['targets'] ?? null)) {
            return $validated;
        }

        foreach ($validated['targets'] as $i => $target) {
            $raw = $rawTargets[$i] ?? null;

            if (is_array($target) && is_array($raw) && array_key_exists('story_extras', $raw)) {
                $validated['targets'][$i]['story_extras'] = is_array($raw['story_extras']) ? $raw['story_extras'] : null;
            }
        }

        return $validated;
    }

    /**
     * The check the rules cannot make: extras only mean something on a platform the assisted lane
     * is offered on. With the kill switch off the extras are ignored on save (Step S-1.1), so
     * there is nothing to refuse.
     *
     * @param  array<int|string, mixed>  $targets
     */
    protected function validateStoryExtrasPlatforms(Validator $validator, array $targets, string $prefix = 'targets'): void
    {
        if (! (bool) config('publishing.story_handoff.enabled', false)) {
            return;
        }

        $withExtras = collect($targets)
            ->filter(fn ($target) => is_array($target) && ! StoryExtras::fromArray($target['story_extras'] ?? null)->isEmpty());

        if ($withExtras->isEmpty()) {
            return;
        }

        $accounts = SocialAccount::query()
            ->whereIn('id', $withExtras->pluck('social_account_id')->filter()->map(fn ($id) => (int) $id)->all())
            ->get()
            ->keyBy('id');

        $capabilities = app(PlatformCapabilities::class);

        foreach ($withExtras as $i => $target) {
            $account = $accounts->get((int) ($target['social_account_id'] ?? 0));

            if ($account !== null && ! $capabilities->supportsStoryExtras($account->platform)) {
                $validator->errors()->add("{$prefix}.{$i}.story_extras", __('Links and stickers are only available on Instagram stories, not on :platform.', [
                    'platform' => $account->platform->label(),
                ]));
            }
        }
    }

    private static function httpsLinkRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (is_string($value) && $value !== '' && ! StoryExtras::isHttpsUrl(trim($value))) {
                $fail(__('Enter a full link, starting with https://.'));
            }
        };
    }
}
