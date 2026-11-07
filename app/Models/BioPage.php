<?php

namespace App\Models;

use App\Enums\BioTheme;
use App\Models\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Step CG-3.1 (B-11.3) — a brand's link in bio: one page per primary brand, published at
 * `/@{handle}`. Like {@see MediaKit} it carries no global org scope — the owner only ever reaches
 * it through the workspace brand, and the public route looks it up by handle — and it renders
 * nothing until `published_at` is set.
 *
 * @property int $id
 * @property int $org_id
 * @property int $client_id
 * @property string $handle
 * @property string|null $title
 * @property string|null $bio
 * @property BioTheme $theme
 * @property Carbon|null $published_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, BioBlock> $blocks
 */
#[Fillable(['org_id', 'client_id', 'handle', 'title', 'bio', 'theme', 'published_at'])]
class BioPage extends Model
{
    /**
     * Handles the app's own URLs and support pages need. The `/@{handle}` prefix means none of
     * these can actually collide, but they're reserved anyway: the handle is a public identity and
     * `@admin` or `@api` would read as Alima's own.
     */
    public const RESERVED_HANDLES = [
        'admin', 'alima', 'api', 'app', 'bio', 'billing', 'connect', 'dashboard', 'help', 'home',
        'kit', 'login', 'me', 'plan', 'portal', 'preview', 'pricing', 'review', 'root', 'settings',
        'share', 'signup', 'super-admin', 'support', 'today', 'www',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'theme' => BioTheme::class,
            'published_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withoutGlobalScope(OrganizationScope::class);
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    /**
     * Step GTM-3.2 — hostnames pointed at this page. Only a verified one serves anything, and the
     * `@{handle}` address stays canonical whatever is here.
     *
     * @return HasMany<BioPageDomain, $this>
     */
    public function domains(): HasMany
    {
        return $this->hasMany(BioPageDomain::class);
    }

    /**
     * @return HasMany<BioBlock, $this>
     */
    public function blocks(): HasMany
    {
        return $this->hasMany(BioBlock::class)->orderBy('position');
    }

    public function isPublished(): bool
    {
        return $this->published_at !== null;
    }

    public function publicUrl(): string
    {
        return route('bio.show', $this->handle);
    }
}
