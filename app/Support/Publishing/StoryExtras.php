<?php

namespace App\Support\Publishing;

/**
 * Step S-1.2 — the extras a Story channel carries (`post_targets.story_extras`, shape v1): the
 * things Instagram only lets a person add from its own app. A link sticker, mentions, a hashtag, a
 * location and a poll, plus a note on where to place them.
 *
 * Each extra is null until somebody adds it. An extra that has been added but not filled in (a
 * link with no URL yet) is kept as it is: the editor autosaves half-typed work, and it is
 * `problems()` — read by the blocking `story_extras` checklist item — that stops it being
 * scheduled. `isEmpty()` therefore answers "has anything been added?", which is the question that
 * decides whether the channel takes the assisted lane.
 *
 * The sticker link is deliberately not `post_targets.link_url`: that one feeds caption composition
 * and the `links` checklist item on every platform.
 */
final readonly class StoryExtras
{
    public const VERSION = 1;

    public const IF_MISSED_FAIL = 'fail';

    public const IF_MISSED_PUBLISH_PLAIN = 'publish_plain';

    public const MAX_URL = 2048;

    public const MAX_LINK_TEXT = 30;

    public const MAX_MENTIONS = 5;

    public const MAX_HANDLE = 30;

    public const MAX_HASHTAG = 100;

    public const MAX_LOCATION = 80;

    public const MAX_POLL_QUESTION = 80;

    public const MIN_POLL_OPTIONS = 2;

    public const MAX_POLL_OPTIONS = 4;

    public const MAX_POLL_OPTION = 25;

    public const MAX_PLACEMENT_NOTE = 280;

    private const HANDLE_PATTERN = '/^[A-Za-z0-9._]{1,30}$/';

    /**
     * @param  array{url: string, text: string|null}|null  $link
     * @param  list<string>|null  $mentions  handles without the `@`
     * @param  string|null  $hashtag  one tag without the `#`
     * @param  array{question: string, options: list<string>}|null  $poll
     */
    public function __construct(
        public ?array $link = null,
        public ?array $mentions = null,
        public ?string $hashtag = null,
        public ?string $location = null,
        public ?array $poll = null,
        public ?string $placementNote = null,
        public string $ifMissed = self::IF_MISSED_FAIL,
    ) {}

    /**
     * Normalise whatever was stored or submitted. Never throws: unknown keys are dropped, handles
     * lose their `@` and the hashtag its `#`. An extra is "added" when its key is present, so a
     * caller that means "not added" leaves the key out.
     */
    public static function fromArray(mixed $data): self
    {
        if (! is_array($data)) {
            return new self;
        }

        $link = null;

        if (is_array($data['link'] ?? null)) {
            $text = self::text($data['link']['text'] ?? null);

            $link = [
                'url' => self::text($data['link']['url'] ?? null) ?? '',
                'text' => $text,
            ];
        }

        $mentions = null;

        if (is_array($data['mentions'] ?? null)) {
            $mentions = array_values(array_unique(array_filter(
                array_map(fn ($handle) => ltrim(trim(is_scalar($handle) ? (string) $handle : ''), '@'), $data['mentions']),
                fn (string $handle) => $handle !== '',
            )));
        }

        $poll = null;

        if (is_array($data['poll'] ?? null)) {
            $options = is_array($data['poll']['options'] ?? null) ? $data['poll']['options'] : [];

            $poll = [
                'question' => self::text($data['poll']['question'] ?? null) ?? '',
                'options' => array_values(array_filter(
                    array_map(fn ($option) => trim(is_scalar($option) ? (string) $option : ''), $options),
                    fn (string $option) => $option !== '',
                )),
            ];
        }

        return new self(
            link: $link,
            mentions: $mentions,
            // A key that is present but blank means "added, not filled in yet" — the request's
            // empty-string-to-null conversion must not make a half-typed sticker vanish.
            hashtag: array_key_exists('hashtag', $data)
                ? ltrim(trim(is_scalar($data['hashtag']) ? (string) $data['hashtag'] : ''), '#')
                : null,
            location: array_key_exists('location', $data)
                ? trim(is_scalar($data['location']) ? (string) $data['location'] : '')
                : null,
            poll: $poll,
            placementNote: self::text($data['placement_note'] ?? null),
            ifMissed: ($data['if_missed'] ?? null) === self::IF_MISSED_PUBLISH_PLAIN
                ? self::IF_MISSED_PUBLISH_PLAIN
                : self::IF_MISSED_FAIL,
        );
    }

    /**
     * The stored shape, or null when there is nothing worth storing.
     *
     * @return array<string, mixed>|null
     */
    public function toArray(): ?array
    {
        if ($this->isEmpty() && $this->placementNote === null && $this->ifMissed === self::IF_MISSED_FAIL) {
            return null;
        }

        return array_filter([
            'version' => self::VERSION,
            'link' => $this->link,
            'mentions' => $this->mentions,
            'hashtag' => $this->hashtag,
            'location' => $this->location,
            'poll' => $this->poll,
            'placement_note' => $this->placementNote,
            'if_missed' => $this->ifMissed === self::IF_MISSED_FAIL ? null : $this->ifMissed,
        ], fn ($value) => $value !== null);
    }

    /**
     * Nothing has been added. A placement note or an `if_missed` choice alone is not an extra.
     */
    public function isEmpty(): bool
    {
        return $this->link === null
            && $this->mentions === null
            && $this->hashtag === null
            && $this->location === null
            && $this->poll === null;
    }

    public function hasLink(): bool
    {
        return $this->link !== null;
    }

    /**
     * Whether the sticker link is one Instagram will take: a full `https` address.
     */
    public function hasValidLink(): bool
    {
        return $this->link !== null && self::isHttpsUrl($this->link['url']);
    }

    public function publishesPlainIfMissed(): bool
    {
        return $this->ifMissed === self::IF_MISSED_PUBLISH_PLAIN;
    }

    /**
     * What still has to be filled in before this can be scheduled, in plain words. Empty when the
     * extras are complete (or there are none).
     *
     * @return list<string>
     */
    public function problems(): array
    {
        $problems = [];

        if ($this->link !== null) {
            if ($this->link['url'] === '') {
                $problems[] = 'the link sticker needs a link.';
            } elseif (! self::isHttpsUrl($this->link['url'])) {
                $problems[] = 'the link must be a full address starting with https://.';
            }
        }

        if ($this->mentions !== null) {
            if ($this->mentions === []) {
                $problems[] = 'the mention needs a handle.';
            } elseif (collect($this->mentions)->contains(fn (string $handle) => preg_match(self::HANDLE_PATTERN, $handle) !== 1)) {
                $problems[] = 'a mention isn’t a valid Instagram handle.';
            }
        }

        if ($this->hashtag !== null && ($this->hashtag === '' || preg_match('/\s|#/u', $this->hashtag) === 1)) {
            $problems[] = $this->hashtag === '' ? 'the hashtag sticker needs a tag.' : 'the hashtag must be one word.';
        }

        if ($this->location === '') {
            $problems[] = 'the location sticker needs a place.';
        }

        if ($this->poll !== null) {
            if ($this->poll['question'] === '') {
                $problems[] = 'the poll needs a question.';
            }

            if (count($this->poll['options']) < self::MIN_POLL_OPTIONS) {
                $problems[] = 'the poll needs at least two options.';
            }
        }

        return $problems;
    }

    /**
     * The extras as the short list a poster works down in Instagram: one row per thing to add,
     * with the value to copy. Rendered by the handoff page, the client review page and the portal,
     * so all three always say the same thing. The link comes first: it is the one that has to be
     * pasted.
     *
     * @return list<array{key: string, label: string, value: string}>
     */
    public function checklist(): array
    {
        $rows = [];

        if ($this->link !== null && $this->link['url'] !== '') {
            $rows[] = ['key' => 'link', 'label' => 'Link sticker', 'value' => $this->link['url']];

            if ($this->link['text'] !== null) {
                $rows[] = ['key' => 'link_text', 'label' => 'Sticker text', 'value' => $this->link['text']];
            }
        }

        foreach ($this->mentions ?? [] as $handle) {
            $rows[] = ['key' => 'mention', 'label' => 'Mention', 'value' => '@'.$handle];
        }

        if ($this->hashtag !== null && $this->hashtag !== '') {
            $rows[] = ['key' => 'hashtag', 'label' => 'Hashtag', 'value' => '#'.$this->hashtag];
        }

        if ($this->location !== null && $this->location !== '') {
            $rows[] = ['key' => 'location', 'label' => 'Location', 'value' => $this->location];
        }

        if ($this->poll !== null) {
            if ($this->poll['question'] !== '') {
                $rows[] = ['key' => 'poll_question', 'label' => 'Poll question', 'value' => $this->poll['question']];
            }

            foreach ($this->poll['options'] as $index => $option) {
                $rows[] = ['key' => 'poll_option', 'label' => 'Poll option '.($index + 1), 'value' => $option];
            }
        }

        return $rows;
    }

    /**
     * The extras in a few words — "a link and a poll" — for copy that names what the Story has.
     */
    public function summary(): string
    {
        $names = array_values(array_filter([
            $this->link !== null ? 'a link' : null,
            $this->mentions !== null ? 'a mention' : null,
            $this->hashtag !== null ? 'a hashtag' : null,
            $this->location !== null ? 'a location' : null,
            $this->poll !== null ? 'a poll' : null,
        ]));

        return match (count($names)) {
            0 => '',
            1 => $names[0],
            default => implode(', ', array_slice($names, 0, -1)).' and '.$names[count($names) - 1],
        };
    }

    public static function isHttpsUrl(string $url): bool
    {
        return mb_strlen($url) <= self::MAX_URL
            && filter_var($url, FILTER_VALIDATE_URL) !== false
            && str_starts_with(mb_strtolower($url), 'https://');
    }

    private static function text(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
