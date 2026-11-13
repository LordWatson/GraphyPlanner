<?php

namespace App\Support\Api;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The one response envelope the REST API uses, so every endpoint looks the same to a client.
 *
 * `{"data": …}` for a single record, `{"data": [...], "meta": {"next_cursor": …}}` for a list.
 * Errors are `{"error": {"code": …, "message": …}}` — see `AuthenticateApiToken`.
 *
 * **Cursor pagination, not pages.** A reporting client walks the whole list while people are still
 * creating posts; offset pages silently skip and repeat rows when the underlying set shifts under
 * them, and the caller cannot tell. A cursor cannot do that. It also means no `COUNT(*)` on every
 * page, which matters on the tables this reads.
 */
final class ApiResponse
{
    /**
     * Largest page a caller may ask for. High enough for a nightly bulk pull to be a handful of
     * requests, low enough that one request cannot build an unbounded response in memory.
     */
    public const MAX_PER_PAGE = 200;

    public const DEFAULT_PER_PAGE = 50;

    /**
     * `$meta` is for things about the answer rather than the record: `meta.notes`, the plain
     * sentences a caller should pass on to a person.
     *
     * @param  array<string, mixed>|list<mixed>  $data
     * @param  array<string, mixed>  $meta
     */
    public static function item(array $data, array $meta = []): JsonResponse
    {
        return response()->json(['data' => $data] + ($meta === [] ? [] : ['meta' => $meta]));
    }

    /**
     * Cursor-paginate `$query` and map each row through `$present`.
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     * @param  callable(mixed): array<string, mixed>  $present
     */
    public static function paginate(Request $request, Builder $query, callable $present): JsonResponse
    {
        $perPage = (int) $request->integer('per_page', self::DEFAULT_PER_PAGE);
        $perPage = max(1, min($perPage, self::MAX_PER_PAGE));

        $page = $query->cursorPaginate($perPage)->withQueryString();

        return response()->json([
            'data' => array_map($present, $page->items()),
            'meta' => [
                'per_page' => $perPage,
                // Null means "that was the last page" — the only end-of-list signal a client needs.
                'next_cursor' => $page->nextCursor()?->encode(),
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    public static function error(string $code, string $message, int $status, array $extra = []): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message] + $extra], $status);
    }
}
