<?php

namespace App\Contracts;

/**
 * Step I-16.2 / M-1.2 — scans an uploaded file before it's stored. Bound in
 * `AppServiceProvider` from `config('security.uploads.virus_scanner')`: `NullVirusScanner` (no
 * scan) by default, `ClamAvVirusScanner` (clamd) in production.
 */
interface VirusScanner
{
    /**
     * @return string|null Null when the file is clean; otherwise a short reason (the signature
     *                     name, or why the scan couldn't run) for the log. Never shown to users.
     */
    public function scan(string $path): ?string;
}
