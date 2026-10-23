<?php

namespace App\Support\DesignImports;

/**
 * Step M-5.9 — pick a file extension for a downloaded import, preferring the original filename's
 * extension and falling back to a sensible one for the mime type.
 */
final class DesignImportExtension
{
    private const BY_MIME = [
        'image/jpeg' => 'jpg',
        'image/jpg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/heic' => 'heic',
        'video/mp4' => 'mp4',
        'video/quicktime' => 'mov',
        'application/pdf' => 'pdf',
    ];

    public static function fromMime(string $mimeType, string $filename = ''): string
    {
        $fromName = pathinfo($filename, PATHINFO_EXTENSION);

        if ($fromName !== '') {
            return strtolower($fromName);
        }

        return self::BY_MIME[strtolower($mimeType)] ?? 'bin';
    }
}
