<?php

namespace App\Services\Security;

use App\Contracts\VirusScanner;

/**
 * No scanner configured (local, tests): every file passes. MIME sniffing and the SVG block in
 * `SafeUpload` still apply.
 */
class NullVirusScanner implements VirusScanner
{
    public function scan(string $path): ?string
    {
        return null;
    }
}
