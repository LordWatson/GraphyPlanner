<?php

namespace Tests\Unit\Support\Publishing;

use App\Support\Publishing\StoryExtras;
use Tests\TestCase;

/**
 * Step S-1.2 — the Story extras DTO: normalising, "has anything been added?", what is still
 * unfinished, and the rows a poster works down.
 */
class StoryExtrasTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function full(): array
    {
        return [
            'version' => 1,
            'link' => ['url' => 'https://example.com/offer', 'text' => 'Shop now'],
            'mentions' => ['acmecoffee', 'roastery.co'],
            'hashtag' => 'summersale',
            'location' => 'Leeds, United Kingdom',
            'poll' => ['question' => 'Which one?', 'options' => ['Blue', 'Green']],
            'placement_note' => 'Link sticker bottom centre.',
        ];
    }

    public function test_anything_that_is_not_an_array_normalises_to_no_extras(): void
    {
        foreach ([null, 'junk', 42, false, new \stdClass] as $junk) {
            $extras = StoryExtras::fromArray($junk);

            $this->assertTrue($extras->isEmpty());
            $this->assertNull($extras->toArray());
            $this->assertSame([], $extras->problems());
            $this->assertSame([], $extras->checklist());
            $this->assertSame('', $extras->summary());
        }
    }

    public function test_junk_values_inside_the_array_never_throw(): void
    {
        $extras = StoryExtras::fromArray([
            'link' => 'https://example.com',
            'mentions' => 'acme',
            'poll' => 'Which one?',
            'placement_note' => ['nested'],
            'if_missed' => ['nested'],
        ]);

        $this->assertTrue($extras->isEmpty());
        $this->assertNull($extras->placementNote);
        $this->assertSame(StoryExtras::IF_MISSED_FAIL, $extras->ifMissed);
        $this->assertNull($extras->toArray());

        $nested = StoryExtras::fromArray([
            'link' => ['url' => ['x'], 'text' => ['y']],
            'mentions' => [['x'], null, 12],
            'hashtag' => ['x'],
            'location' => ['x'],
            'poll' => ['question' => ['x'], 'options' => 'Blue'],
        ]);

        $this->assertSame(['url' => '', 'text' => null], $nested->link);
        $this->assertSame(['12'], $nested->mentions);
        $this->assertSame('', $nested->hashtag);
        $this->assertSame('', $nested->location);
        $this->assertSame(['question' => '', 'options' => []], $nested->poll);
    }

    public function test_unknown_keys_are_dropped(): void
    {
        $extras = StoryExtras::fromArray([
            'link' => ['url' => 'https://example.com/offer', 'text' => 'Shop now', 'colour' => 'red'],
            'gif' => 'party',
            'poll' => ['question' => 'Which one?', 'options' => ['Blue', 'Green'], 'emoji' => true],
        ]);

        $this->assertSame([
            'version' => 1,
            'link' => ['url' => 'https://example.com/offer', 'text' => 'Shop now'],
            'poll' => ['question' => 'Which one?', 'options' => ['Blue', 'Green']],
        ], $extras->toArray());
    }

    public function test_values_are_trimmed_and_blank_poll_options_dropped(): void
    {
        $extras = StoryExtras::fromArray([
            'link' => ['url' => '  https://example.com/offer ', 'text' => '   '],
            'location' => '  Leeds ',
            'poll' => ['question' => ' Which one? ', 'options' => [' Blue ', '', null, 'Green']],
            'placement_note' => '  Top left ',
        ]);

        $this->assertSame(['url' => 'https://example.com/offer', 'text' => null], $extras->link);
        $this->assertSame('Leeds', $extras->location);
        $this->assertSame(['question' => 'Which one?', 'options' => ['Blue', 'Green']], $extras->poll);
        $this->assertSame('Top left', $extras->placementNote);
    }

    public function test_a_placement_note_alone_is_not_an_extra(): void
    {
        $extras = StoryExtras::fromArray(['placement_note' => 'Bottom centre, under the price.']);

        $this->assertTrue($extras->isEmpty());
        $this->assertSame([], $extras->problems());
        // Not an extra, but still worth keeping.
        $this->assertSame(['version' => 1, 'placement_note' => 'Bottom centre, under the price.'], $extras->toArray());
    }

    public function test_an_if_missed_choice_alone_is_not_an_extra(): void
    {
        $extras = StoryExtras::fromArray(['if_missed' => 'publish_plain']);

        $this->assertTrue($extras->isEmpty());
        $this->assertSame(['version' => 1, 'if_missed' => 'publish_plain'], $extras->toArray());

        // The default is not stored at all.
        $this->assertNull(StoryExtras::fromArray(['if_missed' => 'fail'])->toArray());
        $this->assertNull(StoryExtras::fromArray(['version' => 1])->toArray());
    }

    public function test_an_extra_that_was_added_but_left_blank_counts_as_added(): void
    {
        $blank = [
            'hashtag null' => ['hashtag' => null],
            'hashtag empty' => ['hashtag' => ''],
            'location null' => ['location' => null],
            'location empty' => ['location' => '  '],
            'link' => ['link' => ['url' => '', 'text' => null]],
            'link without keys' => ['link' => []],
            'mentions' => ['mentions' => []],
            'poll' => ['poll' => []],
        ];

        foreach ($blank as $case => $data) {
            $extras = StoryExtras::fromArray($data);

            $this->assertFalse($extras->isEmpty(), "{$case} should count as added");
            $this->assertNotNull($extras->toArray(), "{$case} should be stored");
            $this->assertNotSame([], $extras->problems(), "{$case} should be reported as unfinished");
        }

        $this->assertSame('', StoryExtras::fromArray(['hashtag' => null])->hashtag);
        $this->assertSame('', StoryExtras::fromArray(['location' => null])->location);
    }

    public function test_a_key_that_is_left_out_means_not_added(): void
    {
        $extras = StoryExtras::fromArray(['link' => ['url' => 'https://example.com']]);

        $this->assertNull($extras->mentions);
        $this->assertNull($extras->hashtag);
        $this->assertNull($extras->location);
        $this->assertNull($extras->poll);
    }

    public function test_mention_handles_lose_their_at_sign_and_are_de_duplicated(): void
    {
        $extras = StoryExtras::fromArray(['mentions' => ['@acmecoffee', 'acmecoffee', '  @roastery.co ', '', null, '@']]);

        $this->assertSame(['acmecoffee', 'roastery.co'], $extras->mentions);
        $this->assertSame([], $extras->problems());
    }

    public function test_the_hashtag_loses_its_hash(): void
    {
        $this->assertSame('summersale', StoryExtras::fromArray(['hashtag' => '#summersale'])->hashtag);
        $this->assertSame('summersale', StoryExtras::fromArray(['hashtag' => ' ##summersale '])->hashtag);
        $this->assertSame('summersale', StoryExtras::fromArray(['hashtag' => 'summersale'])->hashtag);
    }

    public function test_to_array_round_trips_through_from_array(): void
    {
        $stored = StoryExtras::fromArray($this->full())->toArray();

        $this->assertSame($this->full(), $stored);
        $this->assertSame($stored, StoryExtras::fromArray($stored)->toArray());

        $withIfMissed = StoryExtras::fromArray([...$this->full(), 'if_missed' => 'publish_plain'])->toArray();

        $this->assertSame([...$this->full(), 'if_missed' => 'publish_plain'], $withIfMissed);
        $this->assertSame($withIfMissed, StoryExtras::fromArray($withIfMissed)->toArray());

        // A half-finished extra survives the trip too.
        $unfinished = StoryExtras::fromArray(['link' => ['url' => ''], 'hashtag' => '', 'mentions' => []])->toArray();

        $this->assertSame(['version' => 1, 'link' => ['url' => '', 'text' => null], 'mentions' => [], 'hashtag' => ''], $unfinished);
        $this->assertSame($unfinished, StoryExtras::fromArray($unfinished)->toArray());
    }

    public function test_complete_extras_have_no_problems(): void
    {
        $this->assertSame([], StoryExtras::fromArray($this->full())->problems());
        $this->assertSame([], StoryExtras::fromArray(['link' => ['url' => 'https://example.com']])->problems());
    }

    public function test_problems_names_each_unfinished_extra(): void
    {
        $cases = [
            'the link sticker needs a link.' => ['link' => ['url' => '', 'text' => 'Shop now']],
            'the link must be a full address starting with https://.' => ['link' => ['url' => 'http://example.com/offer']],
            'the mention needs a handle.' => ['mentions' => []],
            'a mention isn’t a valid Instagram handle.' => ['mentions' => ['acmecoffee', 'bad handle!']],
            'the hashtag sticker needs a tag.' => ['hashtag' => ''],
            'the hashtag must be one word.' => ['hashtag' => 'summer sale'],
            'the location sticker needs a place.' => ['location' => ''],
            'the poll needs a question.' => ['poll' => ['question' => '', 'options' => ['Blue', 'Green']]],
            'the poll needs at least two options.' => ['poll' => ['question' => 'Which one?', 'options' => ['Blue']]],
        ];

        foreach ($cases as $problem => $data) {
            $this->assertSame([$problem], StoryExtras::fromArray($data)->problems());
        }
    }

    public function test_problems_covers_the_other_incomplete_shapes(): void
    {
        $this->assertSame(
            ['the link must be a full address starting with https://.'],
            StoryExtras::fromArray(['link' => ['url' => 'example.com/offer']])->problems(),
        );
        $this->assertSame(
            ['a mention isn’t a valid Instagram handle.'],
            StoryExtras::fromArray(['mentions' => [str_repeat('a', 31)]])->problems(),
        );
        $this->assertSame(
            ['the hashtag must be one word.'],
            StoryExtras::fromArray(['hashtag' => 'summer#sale'])->problems(),
        );
        $this->assertSame(
            ['the poll needs a question.', 'the poll needs at least two options.'],
            StoryExtras::fromArray(['poll' => []])->problems(),
        );

        // Every problem at once, in the order the extras are listed.
        $this->assertSame([
            'the link sticker needs a link.',
            'the mention needs a handle.',
            'the hashtag sticker needs a tag.',
            'the location sticker needs a place.',
            'the poll needs a question.',
            'the poll needs at least two options.',
        ], StoryExtras::fromArray(['link' => [], 'mentions' => [], 'hashtag' => '', 'location' => '', 'poll' => []])->problems());
    }

    public function test_checklist_lists_the_link_first_then_each_extra_in_order(): void
    {
        $this->assertSame([
            ['key' => 'link', 'label' => 'Link sticker', 'value' => 'https://example.com/offer'],
            ['key' => 'link_text', 'label' => 'Sticker text', 'value' => 'Shop now'],
            ['key' => 'mention', 'label' => 'Mention', 'value' => '@acmecoffee'],
            ['key' => 'mention', 'label' => 'Mention', 'value' => '@roastery.co'],
            ['key' => 'hashtag', 'label' => 'Hashtag', 'value' => '#summersale'],
            ['key' => 'location', 'label' => 'Location', 'value' => 'Leeds, United Kingdom'],
            ['key' => 'poll_question', 'label' => 'Poll question', 'value' => 'Which one?'],
            ['key' => 'poll_option', 'label' => 'Poll option 1', 'value' => 'Blue'],
            ['key' => 'poll_option', 'label' => 'Poll option 2', 'value' => 'Green'],
        ], StoryExtras::fromArray($this->full())->checklist());
    }

    public function test_checklist_puts_the_link_first_whatever_order_the_keys_arrive_in(): void
    {
        $rows = StoryExtras::fromArray([
            'poll' => ['question' => 'Which one?', 'options' => ['Blue', 'Green']],
            'hashtag' => '#summersale',
            'link' => ['url' => 'https://example.com/offer'],
        ])->checklist();

        $this->assertSame(['link', 'hashtag', 'poll_question', 'poll_option', 'poll_option'], array_column($rows, 'key'));
    }

    public function test_checklist_leaves_out_what_has_not_been_filled_in(): void
    {
        $rows = StoryExtras::fromArray([
            'link' => ['url' => '', 'text' => 'Shop now'],
            'mentions' => [],
            'hashtag' => '',
            'location' => '',
            'poll' => ['question' => '', 'options' => ['Blue']],
            'placement_note' => 'Top left',
        ])->checklist();

        // No link row means no sticker-text row either; the placement note is never a row.
        $this->assertSame([['key' => 'poll_option', 'label' => 'Poll option 1', 'value' => 'Blue']], $rows);
    }

    public function test_summary_names_the_extras_in_a_few_words(): void
    {
        $this->assertSame('', StoryExtras::fromArray(['placement_note' => 'Top left'])->summary());
        $this->assertSame('a link', StoryExtras::fromArray(['link' => ['url' => 'https://example.com']])->summary());
        $this->assertSame('a poll', StoryExtras::fromArray(['poll' => []])->summary());
        $this->assertSame('a link and a poll', StoryExtras::fromArray(['poll' => [], 'link' => []])->summary());
        $this->assertSame('a mention and a hashtag', StoryExtras::fromArray(['mentions' => ['acme'], 'hashtag' => 'sale'])->summary());
        $this->assertSame('a link, a mention and a poll', StoryExtras::fromArray(['link' => [], 'mentions' => [], 'poll' => []])->summary());
        $this->assertSame('a link, a mention, a hashtag, a location and a poll', StoryExtras::fromArray($this->full())->summary());
    }

    public function test_has_valid_link_only_for_a_full_https_address(): void
    {
        $link = fn (string $url) => StoryExtras::fromArray(['link' => ['url' => $url]]);

        $this->assertTrue($link('https://example.com/offer?x=1')->hasValidLink());
        $this->assertTrue($link('HTTPS://EXAMPLE.COM')->hasValidLink());
        $this->assertFalse($link('http://example.com')->hasValidLink());
        $this->assertFalse($link('example.com')->hasValidLink());
        $this->assertFalse($link('')->hasValidLink());
        $this->assertFalse($link('https://example.com/'.str_repeat('a', StoryExtras::MAX_URL))->hasValidLink());
        $this->assertFalse(StoryExtras::fromArray(['hashtag' => 'sale'])->hasValidLink());

        // Added is not the same as valid.
        $this->assertTrue($link('')->hasLink());
        $this->assertFalse(StoryExtras::fromArray([])->hasLink());
    }

    public function test_publishes_plain_if_missed_only_when_chosen(): void
    {
        $this->assertTrue(StoryExtras::fromArray(['if_missed' => 'publish_plain'])->publishesPlainIfMissed());
        $this->assertFalse(StoryExtras::fromArray(['if_missed' => 'fail'])->publishesPlainIfMissed());
        $this->assertFalse(StoryExtras::fromArray(['if_missed' => 'whatever'])->publishesPlainIfMissed());
        $this->assertFalse(StoryExtras::fromArray([])->publishesPlainIfMissed());
        $this->assertSame(StoryExtras::IF_MISSED_FAIL, StoryExtras::fromArray(['if_missed' => 'whatever'])->ifMissed);
    }
}
