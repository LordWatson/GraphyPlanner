<?php

namespace App\Support\Publishing;

use App\Enums\Platform;

/**
 * Everything the app knows about one network, read from `config/platforms.php` by
 * `App\Services\Publishing\PlatformCapabilities`. Immutable: change the config, not this.
 */
final readonly class PlatformSpec
{
    /**
     * @param  list<string>  $requiredSettings
     * @param  array<string, array{label: string, required: bool, help: string|null}>  $settings
     * @param  list<string>  $mediaRatios
     * @param  array{chars: int, lines: int}  $captionFold
     * @param  array<string, FormatSpec>  $formats
     */
    public function __construct(
        public Platform $platform,
        public string $label,
        public string $vendor,
        public string $vendorPrefix,
        public bool $vendorDescription,
        public ?string $coverField,
        public ?string $thumbOffsetField,
        public bool $keepAiOriginals,
        public bool $hostedConnect,
        public int $captionLimit,
        public ?int $titleLimit,
        public bool $hashtags,
        public int $maxHashtags,
        public bool $hashtagSpacer,
        public bool $linksInCaption,
        public ?string $linkField,
        public bool $firstComment,
        public bool $altText,
        public bool $textOnly,
        public array $requiredSettings,
        public array $settings,
        public array $mediaRatios,
        public array $captionFold,
        public string $defaultFormat,
        public array $formats,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(Platform $platform, array $config): self
    {
        $formats = [];

        foreach ((array) ($config['formats'] ?? []) as $key => $format) {
            $formats[(string) $key] = FormatSpec::fromConfig((string) $key, (array) $format);
        }

        $fold = (array) ($config['caption_fold'] ?? []);
        $settings = [];

        foreach ((array) ($config['settings'] ?? []) as $key => $setting) {
            $settings[(string) $key] = [
                'label' => (string) ($setting['label'] ?? $key),
                'required' => (bool) ($setting['required'] ?? false),
                'help' => isset($setting['help']) ? (string) $setting['help'] : null,
            ];
        }
        $defaultFormat = (string) ($config['default_format'] ?? array_key_first($formats) ?? 'post');

        return new self(
            platform: $platform,
            label: (string) ($config['label'] ?? $platform->value),
            vendor: (string) ($config['vendor'] ?? $platform->value),
            vendorPrefix: (string) ($config['vendor_prefix'] ?? $platform->value),
            vendorDescription: (bool) ($config['vendor_description'] ?? false),
            coverField: isset($config['cover_field']) ? (string) $config['cover_field'] : null,
            thumbOffsetField: isset($config['thumb_offset_field']) ? (string) $config['thumb_offset_field'] : null,
            keepAiOriginals: (bool) ($config['keep_ai_originals'] ?? false),
            hostedConnect: (bool) ($config['hosted_connect'] ?? false),
            captionLimit: (int) ($config['caption_limit'] ?? 0),
            titleLimit: isset($config['title_limit']) ? (int) $config['title_limit'] : null,
            hashtags: (bool) ($config['hashtags'] ?? true),
            maxHashtags: (int) ($config['max_hashtags'] ?? 30),
            hashtagSpacer: (bool) ($config['hashtag_spacer'] ?? false),
            linksInCaption: (bool) ($config['links_in_caption'] ?? true),
            linkField: isset($config['link_field']) ? (string) $config['link_field'] : null,
            firstComment: (bool) ($config['first_comment'] ?? false),
            altText: (bool) ($config['alt_text'] ?? false),
            textOnly: (bool) ($config['text_only'] ?? false),
            requiredSettings: array_keys(array_filter($settings, fn (array $setting) => $setting['required'])),
            settings: $settings,
            mediaRatios: array_values(array_map('strval', (array) ($config['media_ratios'] ?? ['original']))),
            captionFold: ['chars' => (int) ($fold['chars'] ?? 240), 'lines' => (int) ($fold['lines'] ?? 5)],
            defaultFormat: isset($formats[$defaultFormat]) ? $defaultFormat : (string) array_key_first($formats),
            formats: $formats,
        );
    }

    public function format(?string $key): FormatSpec
    {
        return $this->formats[$key ?? ''] ?? $this->formats[$this->defaultFormat];
    }

    public function hasFormat(?string $key): bool
    {
        return $key !== null && isset($this->formats[$key]);
    }

    /**
     * The vendor field carrying this platform's own caption (`instagram_title`, ...).
     */
    public function vendorField(string $suffix): string
    {
        return "{$this->vendorPrefix}_{$suffix}";
    }

    /**
     * The shape the frontend reads (the shared `platforms` prop).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'value' => $this->platform->value,
            'label' => $this->label,
            'caption_limit' => $this->captionLimit,
            'title_limit' => $this->titleLimit,
            'hashtags' => $this->hashtags,
            'max_hashtags' => $this->maxHashtags,
            'hashtag_spacer' => $this->hashtagSpacer,
            'links_in_caption' => $this->linksInCaption,
            'link_field' => $this->linkField !== null,
            'first_comment' => $this->firstComment,
            'alt_text' => $this->altText,
            'text_only' => $this->textOnly,
            'keep_ai_originals' => $this->keepAiOriginals,
            'required_settings' => $this->requiredSettings,
            'settings' => (object) $this->settings,
            'media_ratios' => $this->mediaRatios,
            'caption_fold' => $this->captionFold,
            'default_format' => $this->defaultFormat,
            'formats' => array_values(array_map(fn (FormatSpec $format) => $format->toArray(), $this->formats)),
        ];
    }
}
