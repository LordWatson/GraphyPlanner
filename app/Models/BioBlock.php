<?php

namespace App\Models;

use App\Enums\BioBlockType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Step CG-3.1 — one block of a {@see BioPage}, ordered by `position`. `settings` holds the payload
 * its type needs (a `link`'s `label` + `url`, a `latest_posts`' `limit`), validated per type by
 * `SaveBioPageRequest` so nothing unexpected reaches the public page.
 *
 * @property int $id
 * @property int $bio_page_id
 * @property BioBlockType $type
 * @property int $position
 * @property array<string, mixed>|null $settings
 * @property bool $is_visible
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['bio_page_id', 'type', 'position', 'settings', 'is_visible'])]
class BioBlock extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => BioBlockType::class,
            'settings' => 'array',
            'is_visible' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<BioPage, $this>
     */
    public function page(): BelongsTo
    {
        return $this->belongsTo(BioPage::class, 'bio_page_id');
    }
}
