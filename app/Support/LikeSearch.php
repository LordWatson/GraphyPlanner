<?php

namespace App\Support;

/**
 * One place that builds a `LIKE` pattern matching the user's text **literally** — a `%` or `_`
 * they typed is a character, not a wildcard.
 *
 * Why this exists rather than `addcslashes()` at each call site: the escape character has to be
 * named in the SQL (`... like ? escape '…'`), and backslash cannot be used for it. In a MySQL
 * string literal a backslash escapes the next character, so the SQL text `escape '\'` leaves the
 * literal unterminated and MySQL answers with a syntax error. SQLite has no backslash escapes, so
 * the very same statement is valid there — which is exactly how three copies of that bug sat in
 * `ListPostsAction`, `ListClientPostsAction` and `SearchClientAssetsAction` with a fully green
 * SQLite test suite while post and asset search were broken on the production database.
 *
 * `!` needs no escaping in any engine's string literal, so pair this with
 * `whereRaw("lower(col) like ? escape '!'", [LikeSearch::literal($search)])`.
 */
class LikeSearch
{
    /**
     * The escape character named in the SQL. Must stay in step with the `escape '…'` clause at
     * every call site.
     */
    public const ESCAPE = '!';

    /**
     * A lower-cased, `%`-wrapped pattern that matches `$search` literally. Lower-cased because
     * every caller compares it against `lower(column)` — MySQL's default collation is
     * case-insensitive but SQLite's `LIKE` is only so for ASCII, and the two must agree.
     */
    public static function literal(string $search): string
    {
        $escaped = str_replace(
            [self::ESCAPE, '%', '_'],
            [self::ESCAPE.self::ESCAPE, self::ESCAPE.'%', self::ESCAPE.'_'],
            mb_strtolower($search),
        );

        return '%'.$escaped.'%';
    }
}
