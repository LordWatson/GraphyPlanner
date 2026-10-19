<?php

namespace Tests\Unit\Services\Publishing;

use App\Enums\MediaRatio;
use App\Enums\Platform;
use App\Models\User;
use App\Services\AI\AiGuardrailService;
use App\Services\Publishing\PlatformCapabilities;
use App\Support\AI\AiCaptionSuggestion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Step I-3.3 / M-2.1 — the platform capability registry is the one source of truth.
 */
class PlatformCapabilitiesTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_platform_has_a_registry_entry_with_at_least_one_format(): void
    {
        foreach (Platform::cases() as $platform) {
            $this->assertIsArray(config("platforms.{$platform->value}"), "Missing registry entry for {$platform->value}");

            $spec = app(PlatformCapabilities::class)->for($platform);

            $this->assertNotEmpty($spec->formats, "{$platform->value} has no formats");
            $this->assertTrue($spec->hasFormat($spec->defaultFormat));
            $this->assertSame(MediaRatio::Original, MediaRatio::optionsFor($platform)[0]);
        }
    }

    public function test_enum_helpers_read_the_registry(): void
    {
        $this->assertSame(config('platforms.instagram.caption_limit'), Platform::Instagram->captionLimit());
        $this->assertSame(config('platforms.linkedin.max_hashtags'), Platform::LinkedIn->maxHashtags());
        $this->assertSame(config('platforms.linkedin.text_only'), Platform::LinkedIn->isTextOnlyCapable());
        $this->assertSame(config('platforms.tiktok.links_in_caption'), Platform::TikTok->allowsLinksInCaption());
        $this->assertSame(config('platforms.facebook.label'), Platform::Facebook->label());
    }

    public function test_changing_one_config_value_moves_the_enum_the_ai_guardrails_the_frontend_prop_and_the_formats_together(): void
    {
        config([
            'platforms.linkedin.max_hashtags' => 1,
            'platforms.linkedin.caption_limit' => 20,
            'platforms.tiktok.media_ratios' => ['original', '1:1'],
        ]);

        $this->assertSame(1, Platform::LinkedIn->maxHashtags());
        $this->assertSame(20, Platform::LinkedIn->captionLimit());
        $this->assertSame([MediaRatio::Original, MediaRatio::Square], MediaRatio::optionsFor(Platform::TikTok));

        $warnings = app(AiGuardrailService::class)->platformWarnings(
            Platform::LinkedIn,
            new AiCaptionSuggestion(caption: 'A caption that is longer than twenty characters', hashtags: ['#one', '#two']),
        );

        $this->assertCount(2, $warnings);
        $this->assertStringContainsString('up to 1 hashtags', $warnings[0]);
        $this->assertStringContainsString('20-character limit', $warnings[1]);

        $frontend = app(PlatformCapabilities::class)->toFrontend();
        $this->assertSame(20, $frontend['linkedin']['caption_limit']);
        $this->assertSame(['original', '1:1'], $frontend['tiktok']['media_ratios']);
    }

    public function test_formats_are_inferred_from_media_like_before_and_explicit_choices_win(): void
    {
        $registry = app(PlatformCapabilities::class);

        $this->assertSame('feed', $registry->resolveFormat(Platform::Instagram, null, ['image'])->key);
        $this->assertSame('carousel', $registry->resolveFormat(Platform::Instagram, null, ['image', 'image'])->key);
        $this->assertSame('reel', $registry->resolveFormat(Platform::Instagram, null, ['video'])->key);
        $this->assertSame('reel', $registry->resolveFormat(Platform::Instagram, null, ['video', 'document'])->key);
        $this->assertSame('photo', $registry->resolveFormat(Platform::TikTok, null, ['image', 'image'])->key);
        $this->assertSame('video', $registry->resolveFormat(Platform::TikTok, null, ['video'])->key);
        $this->assertSame('post', $registry->resolveFormat(Platform::LinkedIn, null, [])->key);

        // Stories are never inferred, only chosen.
        $this->assertSame('story', $registry->resolveFormat(Platform::Instagram, 'story', ['image'])->key);
        // An unknown explicit value falls back to inference.
        $this->assertSame('feed', $registry->resolveFormat(Platform::Instagram, 'short', ['image'])->key);
    }

    public function test_the_frontend_prop_marks_disabled_platforms(): void
    {
        config(['publishing.rollout.linkedin' => false]);

        $frontend = app(PlatformCapabilities::class)->toFrontend();

        $this->assertFalse($frontend['linkedin']['enabled']);
        $this->assertTrue($frontend['instagram']['enabled']);
        $this->assertNotContains(Platform::LinkedIn, app(PlatformCapabilities::class)->enabledPlatforms());
    }

    public function test_the_shared_platforms_prop_reaches_inertia_pages(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page
                ->where('platforms.instagram.caption_limit', config('platforms.instagram.caption_limit'))
                ->has('platforms.linkedin.formats'));
    }

    /**
     * The grep test from the plan's Done when: no platform's limits are written down anywhere in
     * `app/` — they live in `config/platforms.php` only.
     */
    public function test_no_platform_limit_is_hard_coded_outside_the_registry(): void
    {
        // Only the limits distinctive enough to always mean "this platform's caption limit" — round
        // numbers like 500 or 5000 are common validation maxima elsewhere.
        $limits = collect(config('platforms'))
            ->pluck('caption_limit')
            ->filter(fn ($limit) => is_int($limit) && $limit > 2000 && $limit % 1000 !== 0)
            ->unique()
            ->values();

        $this->assertNotEmpty($limits);

        $offenders = [];

        foreach (File::allFiles(app_path()) as $file) {
            $contents = $file->getContents();

            foreach ($limits as $limit) {
                if (preg_match('/(?<![\d.])'.$limit.'(?![\d.])/', $contents) === 1) {
                    $offenders[] = $file->getRelativePathname()." ({$limit})";
                }
            }

            // A per-platform numeric rule, e.g. `Platform::LinkedIn => 5`.
            if (preg_match('/Platform::\w+(\s*,\s*Platform::\w+)*\s*=>\s*\d/', $contents) === 1) {
                $offenders[] = $file->getRelativePathname().' (per-platform number)';
            }

            if (preg_match('/function (captionLimit|maxHashtags|allowsLinksInCaption|isTextOnlyCapable)\(\)[^{]*\{[^}]*match \(\$this\)/s', $contents) === 1) {
                $offenders[] = $file->getRelativePathname().' (per-platform match)';
            }
        }

        $this->assertSame([], $offenders, 'Platform rules must live in config/platforms.php only.');
    }
}
