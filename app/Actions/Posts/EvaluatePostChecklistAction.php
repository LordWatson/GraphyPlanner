<?php

namespace App\Actions\Posts;

use App\Enums\AssetType;
use App\Enums\MediaRatio;
use App\Models\Asset;
use App\Models\Post;
use App\Models\PostTarget;
use App\Support\Publishing\FormatSpec;
use App\Support\Publishing\PlatformSpec;

class EvaluatePostChecklistAction
{
    private const URL_PATTERN = '/https?:\/\/\S+/i';

    /**
     * Evaluate the spec §6 pre-schedule checklist for a post and return its item-by-item result,
     * ready to be persisted onto `Post::checklist_snapshot` and rendered by the editor UI (§9).
     *
     * The content basics (caption, channels, schedule, media) plus, since Steps I-3.1 to I-3.5 /
     * M-2.2 to M-2.4, a per-channel check of every target against the platform capability
     * registry (`config/platforms.php`), using each target's *effective* values (its overrides or
     * the master): the caption as it publishes fits the limit, the format fits the media, and each
     * item is within that format's aspect, length and size limits. Every failure names the channel
     * and the reason in `detail`, and `fixes` offers one-click corrections the editor can apply.
     *
     * `soft` items warn but never block scheduling.
     *
     * @return array{items: array<int, array{key: string, label: string, passed: bool, soft?: bool, detail?: string, fixes?: list<array<string, mixed>>}>, passed: bool}
     */
    public function __invoke(Post $post): array
    {
        $post->loadMissing('targets.socialAccount', 'assets', 'campaign');

        $targets = $post->targets->filter(fn (PostTarget $target) => $target->socialAccount !== null)->values();
        $targets->each(fn (PostTarget $target) => $target->setRelation('post', $post));

        $hasTargets = $post->targets->isNotEmpty();
        $allTargetsScheduled = $hasTargets && $post->targets->every(
            fn ($target) => filled($target->scheduled_local_date) && filled($target->scheduled_local_time)
        );

        $platforms = $post->targetPlatforms();
        $allPlatformsTextOnly = $platforms !== [] && collect($platforms)->every(fn ($platform) => $platform->isTextOnlyCapable());
        $mediaSatisfied = $post->assets->isNotEmpty() || $allPlatformsTextOnly;

        $captionMissing = $targets->isEmpty()
            ? (blank($post->master_caption) ? ['The caption is empty.'] : [])
            : $targets->filter(fn (PostTarget $target) => blank($target->effectiveCaption()))
                ->map(fn (PostTarget $target) => $this->channel($target).' has no caption.')
                ->values()->all();
        $captionMissing = array_values($captionMissing);

        $checks = $this->channelChecks($post, array_values($targets->all()));

        $items = [
            $this->item('caption', 'Every channel has a caption', $captionMissing),
            ['key' => 'targets', 'label' => 'At least one target account is selected', 'passed' => $hasTargets],
            ['key' => 'schedule', 'label' => 'Every target has a local date and time', 'passed' => $allTargetsScheduled],
            ['key' => 'media', 'label' => 'Media is attached (unless every target platform is text-only)', 'passed' => $mediaSatisfied],
            $this->item('caption_length', 'Captions fit each channel’s limit', $checks['caption_length']),
            $this->item('format', 'Each channel’s format fits the media', $checks['format'], fixes: $checks['format_fixes']),
            $this->item('media_fit', 'Media meets each channel’s limits', $checks['media_fit']),
            $this->item('account_settings', 'Channel settings are complete', $checks['account_settings']),
            $this->item('hashtags', 'Hashtags suit each channel', $checks['hashtags'], soft: true),
            $this->item('links', 'Links will work where they’re posted', $checks['links'], soft: true),
            $this->item('first_comment', 'First comments are supported', $checks['first_comment'], soft: true),
            $this->item('media_framing', 'Media is framed for each channel', $checks['media_framing'], soft: true, fixes: $checks['framing_fixes']),
        ];

        // Campaigns enhancement C-4.2: a soft check — a warning in the editor, never a blocker.
        $campaignTags = $post->campaign->hashtags ?? [];

        if ($campaignTags !== []) {
            $postTags = array_map(fn ($tag) => mb_strtolower(ltrim((string) $tag, '#')), $post->hashtags ?? []);

            $items[] = [
                'key' => 'campaign_hashtags',
                'label' => 'Campaign hashtags are included',
                'passed' => collect($campaignTags)->every(fn ($tag) => in_array(mb_strtolower(ltrim((string) $tag, '#')), $postTags, true)),
                'soft' => true,
            ];
        }

        return [
            'items' => $items,
            'passed' => collect($items)->every(fn (array $item) => $item['passed'] || ($item['soft'] ?? false)),
        ];
    }

    /**
     * @param  list<PostTarget>  $targets
     * @return array<string, list<mixed>>
     */
    private function channelChecks(Post $post, array $targets): array
    {
        $checks = array_fill_keys([
            'caption_length', 'format', 'format_fixes', 'media_fit', 'account_settings',
            'hashtags', 'links', 'first_comment', 'media_framing', 'framing_fixes',
        ], []);

        $media = $post->publishableMedia();
        $kinds = $post->mediaKinds();

        foreach ($targets as $target) {
            $account = $target->socialAccount;
            $spec = $account->platform->spec();
            $format = $target->resolvedFormat() ?? $spec->format(null);
            $name = $this->channel($target);

            $published = $target->publishedCaption();
            $length = mb_strlen($published);

            if ($length > $spec->captionLimit) {
                $checks['caption_length'][] = "{$name}: ".number_format($length).'/'.number_format($spec->captionLimit).' characters.';
            }

            $this->checkFormat($checks, $target, $spec, $format, $kinds);
            $this->checkMedia($checks, $target, $spec, $format, array_values($media->all()));

            foreach ($spec->requiredSettings as $setting) {
                if (blank($account->platform_settings[$setting] ?? null)) {
                    $checks['account_settings'][] = "{$name}: ".self::settingPrompt($setting);
                }
            }

            $tags = $target->effectiveHashtags();

            if ($tags !== [] && ! $spec->hashtags) {
                $checks['hashtags'][] = "{$spec->label} doesn’t use hashtags — they won’t be posted there.";
            } elseif (count($tags) > $spec->maxHashtags) {
                $checks['hashtags'][] = "{$name}: ".count($tags)." hashtags (works best with up to {$spec->maxHashtags}).";
            }

            $link = $target->effectiveLink();

            if (! $spec->linksInCaption && preg_match(self::URL_PATTERN, $target->effectiveCaption()) === 1) {
                $checks['links'][] = "{$name}: links in captions aren’t clickable — use the link in bio instead.";
            } elseif ($link !== null && $spec->linkField === null && ! $spec->linksInCaption) {
                $checks['links'][] = "{$name}: the link won’t be posted — {$spec->label} doesn’t show links on posts.";
            }

            if ($target->effectiveFirstComment() !== null && ! $spec->firstComment) {
                $checks['first_comment'][] = "{$spec->label} doesn’t support first comments — it won’t be posted there.";
            }
        }

        foreach (['hashtags', 'first_comment'] as $key) {
            $checks[$key] = array_values(array_unique($checks[$key]));
        }

        return $checks;
    }

    /**
     * @param  array<string, list<mixed>>  $checks
     * @param  list<string>  $kinds
     */
    private function checkFormat(array &$checks, PostTarget $target, PlatformSpec $spec, FormatSpec $format, array $kinds): void
    {
        $count = count($kinds);

        // No media at all is the `media` item's job (or fine, for a text-capable format).
        if ($count === 0) {
            return;
        }

        $name = $this->channel($target);
        $label = mb_strtolower($format->label);
        $wrongKind = collect($kinds)->first(fn (string $kind) => ! $format->accepts($kind));

        if ($wrongKind !== null) {
            $checks['format'][] = "{$name}: a {$label} takes ".self::kindList($format->media).' — this post sends '.self::describeMedia($kinds).'.';
        } elseif ($count < $format->minItems || $count > $format->maxItems) {
            $range = $format->minItems === $format->maxItems ? (string) $format->maxItems : "{$format->minItems}–{$format->maxItems}";
            $noun = $format->maxItems === 1 ? 'item' : 'items';
            $checks['format'][] = "{$name}: a {$label} takes {$range} {$noun} — this post sends {$count}.";
        } else {
            return;
        }

        // Offer the formats on this platform that would fit as they are.
        foreach ($spec->formats as $candidate) {
            $fits = $count >= $candidate->minItems && $count <= $candidate->maxItems
                && collect($kinds)->every(fn (string $kind) => $candidate->accepts($kind));

            if ($fits && $candidate->key !== $format->key) {
                $checks['format_fixes'][] = [
                    'action' => 'format',
                    'social_account_id' => $target->social_account_id,
                    'value' => $candidate->key,
                    'label' => "Post to {$name} as a ".mb_strtolower($candidate->label),
                ];
            }
        }
    }

    /**
     * @param  array<string, list<mixed>>  $checks
     * @param  list<Asset>  $media
     */
    private function checkMedia(array &$checks, PostTarget $target, PlatformSpec $spec, FormatSpec $format, array $media): void
    {
        $name = $this->channel($target);
        $label = mb_strtolower($format->label);
        $letterboxed = false;

        foreach ($media as $asset) {
            $file = $asset->original_filename ?: 'This file';
            $isVideo = $asset->type === AssetType::Video;
            $limitMb = $isVideo ? $format->maxVideoMb : $format->maxImageMb;

            if ($limitMb !== null && $asset->size !== null && $asset->size > $limitMb * 1024 * 1024) {
                $checks['media_fit'][] = "{$name}: {$file} is ".self::megabytes($asset->size)." — a {$label} allows up to {$limitMb} MB.";
            }

            if ($isVideo && $asset->duration_ms !== null) {
                $seconds = (int) round($asset->duration_ms / 1000);

                if ($format->maxVideoSeconds !== null && $seconds > $format->maxVideoSeconds) {
                    $checks['media_fit'][] = "{$name}: the video is ".self::duration($seconds).' long — a '.$label.' can be up to '.self::duration($format->maxVideoSeconds).'.';
                }

                if ($format->minVideoSeconds !== null && $seconds < $format->minVideoSeconds) {
                    $checks['media_fit'][] = "{$name}: the video is ".self::duration($seconds).' — a '.$label.' must be at least '.self::duration($format->minVideoSeconds).'.';
                }
            }

            $aspect = $asset->aspectRatio();

            // Images are cut to the format before publishing (`PublishImageCropper`); a video goes
            // out as uploaded, so its shape has to fit already.
            if ($isVideo && $aspect !== null && $format->aspect !== null) {
                [$min, $max] = $format->aspect;

                if ($aspect < $min - 0.01 || $aspect > $max + 0.01) {
                    $checks['media_fit'][] = "{$name}: a {$label} needs ".self::shapeOf($format).' video — this one is '.self::ratioLabel($aspect).'.';
                }
            }

            // A full-screen format (`fill_screen`, e.g. TikTok photo mode) shows an image of any
            // other shape on black bars unless the post crops it for this channel.
            if (! $isVideo && $aspect !== null && $format->fillScreen !== null
                && $target->post?->mediaRatioFor($spec->platform)->quotient() === null
                && abs($aspect - (MediaRatio::tryFrom($format->fillScreen)?->quotient() ?? $aspect)) > 0.02) {
                $letterboxed = true;
            }
        }

        if ($letterboxed && $format->fillScreen !== null) {
            $checks['media_framing'][] = "{$name}: these images will show with black bars — crop them to fill the screen.";
            $checks['framing_fixes'][] = [
                'action' => 'media_format',
                'platform' => $spec->platform->value,
                'value' => $format->fillScreen,
                'label' => "Crop {$spec->label} images to {$format->fillScreen}",
            ];
        }
    }

    /**
     * @param  list<string>  $problems
     * @param  list<mixed>  $fixes
     * @return array{key: string, label: string, passed: bool, soft?: bool, detail?: string, fixes?: list<array<string, mixed>>}
     */
    private function item(string $key, string $label, array $problems, bool $soft = false, array $fixes = []): array
    {
        $item = ['key' => $key, 'label' => $label, 'passed' => $problems === []];

        if ($soft) {
            $item['soft'] = true;
        }

        if ($problems !== []) {
            $item['detail'] = implode("\n", array_values(array_unique($problems)));
        }

        if ($problems !== [] && $fixes !== []) {
            /** @var list<array<string, mixed>> $unique */
            $unique = array_values(array_unique($fixes, SORT_REGULAR));
            $item['fixes'] = $unique;
        }

        return $item;
    }

    private function channel(PostTarget $target): string
    {
        $account = $target->socialAccount;

        return $account ? "{$account->platform->label()} ({$account->handle})" : 'A channel';
    }

    private static function settingPrompt(string $setting): string
    {
        return match ($setting) {
            'pinterest_board_id' => 'choose the board to pin to in the account’s settings.',
            default => 'finish the account’s settings ('.str_replace('_', ' ', $setting).').',
        };
    }

    /**
     * @param  list<string>  $kinds
     */
    private static function kindList(array $kinds): string
    {
        return match (true) {
            $kinds === ['video'] => 'a video',
            $kinds === ['image'] => 'images',
            default => 'images or a video',
        };
    }

    /**
     * @param  list<string>  $kinds
     */
    private static function describeMedia(array $kinds): string
    {
        $count = count($kinds);

        return in_array('video', $kinds, true) ? 'a video' : ($count === 1 ? '1 image' : "{$count} images");
    }

    private static function shapeOf(FormatSpec $format): string
    {
        [$min, $max] = $format->aspect ?? [0, 0];

        return match (true) {
            $max <= 0.6 => 'vertical 9:16',
            $max <= 1.0 => 'vertical or square',
            default => self::ratioLabel($min).' to '.self::ratioLabel($max),
        };
    }

    private static function ratioLabel(float $aspect): string
    {
        foreach (['9:16' => 9 / 16, '4:5' => 0.8, '1:1' => 1.0, '16:9' => 16 / 9, '1.91:1' => 1.91, '4:3' => 4 / 3, '3:4' => 0.75] as $label => $value) {
            if (abs($aspect - $value) < 0.02) {
                return $label;
            }
        }

        return $aspect >= 1 ? round($aspect, 2).':1' : '1:'.round(1 / $aspect, 2);
    }

    private static function duration(int $seconds): string
    {
        return $seconds >= 60 ? sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60) : "{$seconds}s";
    }

    private static function megabytes(int $bytes): string
    {
        return round($bytes / 1024 / 1024, 1).' MB';
    }
}
