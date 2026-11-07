<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Step GTM-3.2 — one hostname pointed at a {@see BioPage}. No `OrganizationScope`, like `BioPage`
 * itself: the owner only reaches it through the workspace brand, and the public lookup is by
 * hostname.
 *
 * @property int $id
 * @property int $bio_page_id
 * @property string $hostname
 * @property string $verification_token
 * @property Carbon|null $verified_at
 * @property string $ssl_status
 * @property Carbon|null $last_checked_at
 * @property string|null $last_error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['bio_page_id', 'hostname', 'verification_token', 'verified_at', 'ssl_status', 'last_checked_at', 'last_error'])]
class BioPageDomain extends Model
{
    /** A creator can't be asked to manage a dozen hostnames, and each one is a nightly DNS lookup. */
    public const MAX_PER_PAGE = 3;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
            'last_checked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<BioPage, $this>
     */
    public function page(): BelongsTo
    {
        return $this->belongsTo(BioPage::class, 'bio_page_id');
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    public static function newToken(): string
    {
        return 'alima-verify-'.Str::lower(Str::random(24));
    }

    /**
     * Normalised on the way in: lowercase, no scheme, no trailing dot or slash, no port. A hostname
     * is case-insensitive, so storing it any other way would let two rows claim the same name.
     */
    public static function normaliseHostname(string $hostname): string
    {
        $hostname = Str::lower(trim($hostname));
        $hostname = (string) preg_replace('#^https?://#', '', $hostname);
        $hostname = explode('/', $hostname)[0];
        $hostname = explode(':', $hostname)[0];

        return trim($hostname, '.');
    }
}
