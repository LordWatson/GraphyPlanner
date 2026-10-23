<?php

namespace App\Support\Posts;

/**
 * Step M-5.8 (Phase 10) — the outcome of parsing a bulk-import CSV: the valid rows and, separately,
 * the rows that couldn't be read, each with its 1-based row number and the reasons. A row is never
 * half-parsed: it either yields a `BulkPostRow` or is reported.
 */
final readonly class BulkImportParseResult
{
    /**
     * @param  list<BulkPostRow>  $rows
     * @param  list<array{row: int, messages: list<string>}>  $errors
     */
    public function __construct(
        public array $rows,
        public array $errors,
    ) {}

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    public function isEmpty(): bool
    {
        return $this->rows === [] && $this->errors === [];
    }
}
