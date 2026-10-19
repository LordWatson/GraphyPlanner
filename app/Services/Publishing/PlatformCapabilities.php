<?php

namespace App\Services\Publishing;

use App\Enums\Platform;
use App\Support\Publishing\FormatSpec;
use App\Support\Publishing\PlatformSpec;

/**
 * Step I-3.3 / M-2.1 — the platform capability registry. Reads `config/platforms.php` once per
 * request and answers every "what does this network accept?" question: the checklist, the editor
 * (via the shared `platforms` Inertia prop), the AI guardrails and the publish adapter all ask
 * here, so one config change moves them together.
 *
 * Deliberately not a singleton: specs are cached per instance only, so a runtime `config([...])`
 * change (tests, rollout flags) is picked up by the next `app(PlatformCapabilities::class)`.
 */
class PlatformCapabilities
{
    /** @var array<string, PlatformSpec> */
    private array $specs = [];

    public function __construct(private readonly PlatformRolloutGate $rollout = new PlatformRolloutGate) {}

    public function for(Platform $platform): PlatformSpec
    {
        return $this->specs[$platform->value] ??= PlatformSpec::fromConfig(
            $platform,
            (array) config("platforms.{$platform->value}", []),
        );
    }

    /**
     * Every platform, in enum order.
     *
     * @return list<PlatformSpec>
     */
    public function all(): array
    {
        return array_map(fn (Platform $platform) => $this->for($platform), Platform::cases());
    }

    /**
     * Platforms with publishing switched on (Step 1.7 rollout flags) — what the editor and the
     * account form offer.
     *
     * @return list<Platform>
     */
    public function enabledPlatforms(): array
    {
        return array_values(array_filter(Platform::cases(), fn (Platform $platform) => $this->rollout->platformEnabled($platform)));
    }

    /**
     * The format a target publishes as: its explicit choice when the platform offers it, else the
     * first format (in registry order) whose media kinds and item bounds fit the attached media,
     * else the platform default. Formats with a forced crop (Stories) are only ever explicit.
     *
     * @param  list<string>  $mediaKinds  `image` / `video` per publishable attached item, in order
     */
    public function resolveFormat(Platform $platform, ?string $explicit, array $mediaKinds): FormatSpec
    {
        $spec = $this->for($platform);

        if ($spec->hasFormat($explicit)) {
            return $spec->format($explicit);
        }

        $count = count($mediaKinds);

        foreach ($spec->formats as $format) {
            if ($format->cropTo !== null) {
                continue;
            }

            $fits = $count >= $format->minItems
                && $count <= $format->maxItems
                && collect($mediaKinds)->every(fn (string $kind) => $format->accepts($kind));

            if ($fits) {
                return $format;
            }
        }

        // A video post sends one video, so a single-video format wins over the default when the
        // media is "a video plus other files".
        if (in_array('video', $mediaKinds, true)) {
            foreach ($spec->formats as $format) {
                if ($format->cropTo === null && $format->accepts('video') && $format->maxItems === 1) {
                    return $format;
                }
            }
        }

        return $spec->format(null);
    }

    /**
     * The single `platforms` prop the frontend reads (`usePlatforms()`), keyed by platform value.
     *
     * @return array<string, array<string, mixed>>
     */
    public function toFrontend(): array
    {
        $enabled = array_map(fn (Platform $platform) => $platform->value, $this->enabledPlatforms());

        return collect($this->all())
            ->mapWithKeys(fn (PlatformSpec $spec) => [
                $spec->platform->value => [...$spec->toArray(), 'enabled' => in_array($spec->platform->value, $enabled, true)],
            ])
            ->all();
    }
}
