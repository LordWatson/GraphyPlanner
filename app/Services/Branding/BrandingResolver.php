<?php

namespace App\Services\Branding;

use App\Contracts\AssetStorage;
use App\Contracts\Entitlements;
use App\Enums\Feature;
use App\Models\Organization;
use App\Support\Branding\Branding;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Step GTM-3.1 — resolves one organization's client-facing branding, in one place, so the client
 * portal, the monthly report and outbound mail can never disagree about who the client is dealing
 * with. Memoised per instance (the resolver is request-scoped) because every surface asks for it
 * and the logo URL costs a signed-URL mint.
 *
 * Two rules it owns:
 *
 *  - **The plan decides.** Without `Feature::WhiteLabel` it returns {@see Branding::alima()},
 *    whatever is stored on the org. Downgrading a plan therefore takes the branding off every
 *    client surface at once, with no migration and no stale cache.
 *  - **Only the client-facing surfaces.** Nothing here is for the agency's own studio shell. The
 *    accent replaces Deep Teal on a client surface; it never touches Signal Orange or the Alima
 *    marks, which are ours (brand guidelines §3).
 */
class BrandingResolver
{
    /** @var array<int, Branding> */
    private array $resolved = [];

    public function __construct(
        private readonly Entitlements $entitlements,
        private readonly AssetStorage $storage,
    ) {}

    public function for(?Organization $organization): Branding
    {
        if ($organization === null) {
            return Branding::alima();
        }

        return $this->resolved[$organization->id] ??= $this->resolve($organization);
    }

    /**
     * The logo as a `data:` URI for dompdf, which cannot fetch a URL. Null when there is no logo,
     * when the plan doesn't include white labelling, or when the file has gone — the report then
     * falls back to the Alima mark rather than rendering a broken image.
     */
    public function logoDataUri(?Organization $organization): ?string
    {
        $branding = $this->for($organization);

        if (! $branding->isWhiteLabel || $organization?->brand_logo_path === null) {
            return null;
        }

        try {
            $bytes = $this->storage->contents((string) $organization->brand_logo_disk, (string) $organization->brand_logo_path);
        } catch (Throwable $e) {
            $bytes = null;
            Log::warning('Could not read a white-label logo for a PDF', [
                'org_id' => $organization->id,
                'exception' => $e::class,
            ]);
        }

        return $bytes === null
            ? null
            : 'data:'.self::mimeFor((string) $organization->brand_logo_path).';base64,'.base64_encode($bytes);
    }

    /**
     * How long a logo URL in an email stays good for. Long, because an email is read days after it
     * is sent and a signed URL that has lapsed shows a broken image; not forever, because the file
     * is on a private disk on purpose.
     */
    public const MAIL_LOGO_TTL_MINUTES = 60 * 24 * 30;

    public function mailLogoUrl(?Organization $organization): ?string
    {
        $branding = $this->for($organization);

        if (! $branding->isWhiteLabel || $organization?->brand_logo_path === null) {
            return null;
        }

        return $this->storage->signedUrl(
            (string) $organization->brand_logo_disk,
            (string) $organization->brand_logo_path,
            self::MAIL_LOGO_TTL_MINUTES,
        );
    }

    private function resolve(Organization $organization): Branding
    {
        if (! $this->entitlements->allows($organization, Feature::WhiteLabel)) {
            return Branding::alima();
        }

        return new Branding(
            isWhiteLabel: true,
            name: $organization->name,
            accent: $organization->brand_accent ?: Branding::DEFAULT_ACCENT,
            logoUrl: $organization->brand_logo_path === null
                ? null
                : $this->storage->url((string) $organization->brand_logo_disk, (string) $organization->brand_logo_path),
            senderName: $organization->brand_sender_name ?: $organization->name,
            replyTo: $organization->brand_reply_to,
            footer: $organization->brand_footer,
        );
    }

    private static function mimeFor(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'webp' => 'image/webp',
            default => 'image/jpeg',
        };
    }
}
