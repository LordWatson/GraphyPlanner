<?php

namespace App\Support\DesignImports;

/**
 * Step M-5.9 — a page of importable files from a design/cloud source.
 */
final readonly class DesignImportListing
{
    /**
     * @param  list<DesignFile>  $files
     */
    public function __construct(
        public array $files,
        public ?string $nextCursor = null,
    ) {}
}
