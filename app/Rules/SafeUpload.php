<?php

namespace App\Rules;

use App\Contracts\VirusScanner;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

/**
 * Step I-16.2 / M-1.2 — upload hardening on top of the `mimes:` rule:
 *
 * - the file name's extension must be on the allowlist;
 * - the type sniffed from the file's **content** must fit that extension (a `.jpg` must really
 *   be an image, a `.pdf` really a PDF), so a renamed HTML or script file is refused;
 * - SVG (which can carry script), HTML, XML and executable/script types are always refused;
 * - the file is passed to the `VirusScanner` (ClamAV in production).
 */
class SafeUpload implements ValidationRule
{
    /**
     * Content types refused whatever the extension says.
     */
    private const BLOCKED_TYPES = [
        'image/svg+xml', 'text/html', 'application/xhtml+xml', 'text/xml', 'application/xml',
        'application/javascript', 'text/javascript', 'application/x-httpd-php', 'text/x-php',
        'application/x-php', 'application/x-sh', 'text/x-shellscript', 'application/x-msdownload',
        'application/x-dosexec', 'application/x-executable',
    ];

    /**
     * What the content may sniff as, per extension. A trailing `/` is a prefix match.
     */
    private const ALLOWED_TYPES = [
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'gif' => ['image/gif'],
        'webp' => ['image/webp'],
        // Older libmagic builds don't know HEIC and report a generic binary.
        'heic' => ['image/heic', 'image/heif', 'application/octet-stream'],
        'mp4' => ['video/'],
        'mov' => ['video/'],
        'webm' => ['video/', 'audio/webm'],
        'avi' => ['video/'],
        'mkv' => ['video/'],
        'm4v' => ['video/'],
        'pdf' => ['application/pdf'],
        'doc' => ['application/msword', 'application/vnd.ms-office', 'application/cdfv2', 'application/x-ole-storage'],
        'ppt' => ['application/vnd.ms-powerpoint', 'application/vnd.ms-office', 'application/cdfv2', 'application/x-ole-storage'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip'],
        'zip' => ['application/zip', 'application/x-zip-compressed'],
    ];

    /**
     * @param  list<string>  $extensions
     */
    public function __construct(private readonly array $extensions) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! $value->isValid()) {
            return; // the `file` rule reports this one
        }

        $extension = strtolower($value->getClientOriginalExtension());
        $type = strtolower((string) $value->getMimeType());

        if (! in_array($extension, $this->extensions, true) || ! self::typeFits($extension, $type)) {
            Log::warning('Upload rejected: content does not match an allowed type', [
                'extension' => $extension,
                'sniffed_type' => $type,
            ]);

            $fail(__('This file type isn’t supported, or the file isn’t what its name says.'));

            return;
        }

        if (($reason = app(VirusScanner::class)->scan($value->getRealPath() ?: $value->getPathname())) !== null) {
            Log::warning('Upload rejected by the virus scanner', ['extension' => $extension, 'reason' => $reason]);

            $fail(__('This file didn’t pass our security scan, so it wasn’t uploaded.'));
        }
    }

    public static function typeFits(string $extension, string $type): bool
    {
        if (in_array($type, self::BLOCKED_TYPES, true)) {
            return false;
        }

        foreach (self::ALLOWED_TYPES[$extension] ?? [] as $allowed) {
            if (str_ends_with($allowed, '/') ? str_starts_with($type, $allowed) : $type === $allowed) {
                return true;
            }
        }

        return false;
    }
}
