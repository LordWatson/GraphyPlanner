<?php

namespace Tests\Unit\Support;

use App\Support\LikeSearch;
use PHPUnit\Framework\TestCase;

/**
 * The pattern builder behind post and asset search. Pure string work, so a plain unit test.
 *
 * The thing worth guarding is the *escape character*: it must never be a backslash. MySQL treats a
 * backslash inside a string literal as an escape, so the SQL text `escape '\'` leaves the literal
 * unterminated and the statement is rejected — while SQLite, which the suite runs on, accepts the
 * same statement happily. Three call sites shipped with exactly that, so the search box 500'd on
 * MySQL with a green test suite.
 */
class LikeSearchTest extends TestCase
{
    public function test_the_escape_character_is_never_a_backslash(): void
    {
        $this->assertSame('!', LikeSearch::ESCAPE);
        $this->assertStringNotContainsString('\\', LikeSearch::literal('plain'));
    }

    public function test_it_wraps_and_lower_cases_the_term(): void
    {
        $this->assertSame('%summer-hero%', LikeSearch::literal('Summer-HERO'));
    }

    public function test_wildcards_the_user_typed_are_escaped(): void
    {
        $this->assertSame('%50!%!_off%', LikeSearch::literal('50%_off'));
    }

    public function test_the_escape_character_itself_is_escaped(): void
    {
        // Otherwise `!%` typed by a user would turn the following character into a literal and
        // quietly change what the search matches.
        $this->assertSame('%wow!!!%%', LikeSearch::literal('wow!%'));
    }

    public function test_a_backslash_the_user_typed_survives_untouched(): void
    {
        // It is not special to LIKE, and it must not become one.
        $this->assertSame('%c:\\users%', LikeSearch::literal('C:\\Users'));
    }

    public function test_an_empty_term_matches_everything(): void
    {
        // Callers never pass one (they check `$search !== ''` first), but the pattern should still
        // be the harmless one rather than something malformed.
        $this->assertSame('%%', LikeSearch::literal(''));
    }
}
