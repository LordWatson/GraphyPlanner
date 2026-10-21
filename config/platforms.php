<?php

/*
|--------------------------------------------------------------------------
| Platform capability registry (Step I-3.3 / M-2.1)
|--------------------------------------------------------------------------
|
| The one source of truth for what each network accepts: caption and hashtag limits, link policy,
| first comment and alt text support, the formats a post can take there, and the media each format
| allows. `App\Services\Publishing\PlatformCapabilities` reads it, and the checklist, the editor
| counters and previews (shared as the `platforms` Inertia prop), the AI guardrails and the
| Upload-Post adapter all go through that service. Change a value here and every one of them
| follows. Never hard-code a platform rule anywhere else (a grep test enforces it).
|
| Sources: Upload-Post's upload docs (docs.upload-post.com/api/upload-photo, /upload-video,
| /upload-text, checked 19 Oct 2026) for field names, first comment, Stories and carousel bounds,
| and each network's published limits for the rest. See `.junie/modules/publishing.md`.
|
| Keys per platform:
| - `label`: display name.
| - `vendor`: the Upload-Post `platform[]` value; `vendor_prefix`: the `{prefix}_title` /
|   `{prefix}_first_comment` / `{prefix}_alt_text` field prefix; `vendor_description`: whether the
|   platform renders the generic `description` field as the post body (Instagram and X ignore it);
|   `cover_field` / `thumb_offset_field`: where a video's cover image URL and frame offset (ms) go;
|   `keep_ai_originals`: AI-generated images go out untouched (LinkedIn has no API label and reads
|   the C2PA credentials embedded in the original, which re-encoding would strip);
|   `hosted_connect`: the vendor's hosted connect page can be limited to this platform (its
|   `generate-jwt` `platforms` list doesn't include Pinterest).
| - `account_metrics` / `post_metrics` (Step M-3.1, default true): the vendor reports account-level
|   (followers, reach) / per-post engagement here. Off → the app shows "not available for
|   {platform}" instead of zeros (M-0.2 audit: Google Business has no per-post metrics).
| - `caption_limit`: characters, counted on the caption exactly as it publishes (hashtags and an
|   appended link included). `title_limit`: for platforms that also need a title (YouTube, Pinterest).
| - `hashtags`: whether tags are sent at all; `max_hashtags`: the platform's norm (a warning, not a
|   block); `hashtag_spacer`: push tags below the "more" fold with the dot-dash spacer.
| - `links_in_caption`: whether a URL in the caption is clickable / welcome there.
|   `link_field`: the vendor field that carries the post's link instead (Pinterest, Google).
| - `first_comment`, `alt_text`: supported by the vendor for this platform.
| - `text_only`: a post with no media can publish here.
| - `settings`: per-account settings (`social_accounts.platform_settings`), keyed by the vendor
|   field each one is sent as, with a label, help text and whether the vendor needs it before it
|   will publish (e.g. the Pinterest board).
| - `media_ratios`: the image framings the editor offers (`App\Enums\MediaRatio` values; the first
|   is the default). `ai_image_ratio`: the Image Studio's default shape for this platform's feed
|   (`App\Enums\ImageAspectRatio`).
| - `caption_fold`: roughly where the app hides the rest behind "more" (preview only).
| - `formats`: the explicit formats a target can take, in display order. `default_format` is used
|   when the target is left on "Auto" and its media doesn't pick a better match (see
|   `PlatformCapabilities::inferFormat()`). Each format:
|     - `label`, `vendor_media_type` (+ `vendor_media_type_video` when a video needs another value,
|       and `vendor_media_type_field`, default `media_type`),
|     - `media`: the kinds it accepts (`image`, `video`); `min_items` / `max_items` (min 0 = text),
|     - `aspect`: [min, max] width ÷ height the platform accepts, or null for anything,
|     - `crop_to`: the framing images are cut to for this format (overrides `media_ratios`),
|     - `vertical`: a full-screen vertical placement (Reels, Stories, TikTok, Shorts) — previews
|       render it as a phone screen,
|     - `fill_screen`: the format plays full screen at this ratio, so other shapes get black bars
|       (the checklist offers a one-click crop),
|     - `min_video_seconds` / `max_video_seconds`, `max_image_mb` / `max_video_mb`.
|
*/

$story = [
    'label' => 'Story',
    'vertical' => true,
    'media' => ['image', 'video'],
    'min_items' => 1,
    'max_items' => 1,
    'aspect' => [0.54, 0.58],
    'crop_to' => '9:16',
    'max_video_seconds' => 60,
    'max_image_mb' => 8,
    'max_video_mb' => 100,
];

return [

    'instagram' => [
        'label' => 'Instagram',
        'vendor' => 'instagram',
        'vendor_prefix' => 'instagram',
        'vendor_description' => false,
        'cover_field' => 'cover_url',
        'thumb_offset_field' => 'thumb_offset',
        'keep_ai_originals' => false,
        'hosted_connect' => true,
        'caption_limit' => 2200,
        'hashtags' => true,
        'max_hashtags' => 30,
        'hashtag_spacer' => true,
        'links_in_caption' => false,
        'link_field' => null,
        'first_comment' => true,
        'alt_text' => true,
        'text_only' => false,
        'settings' => [],
        'media_ratios' => ['original', '1:1', '4:5', '3:4', '1.91:1'],
        'ai_image_ratio' => '4:5',
        'caption_fold' => ['chars' => 125, 'lines' => 2],
        'default_format' => 'feed',
        'formats' => [
            'feed' => [
                'label' => 'Feed post',
                'vendor_media_type' => 'IMAGE',
                'media' => ['image'],
                'min_items' => 1,
                'max_items' => 1,
                'aspect' => [0.8, 1.91],
                'max_image_mb' => 8,
            ],
            'carousel' => [
                'label' => 'Carousel',
                'vendor_media_type' => 'IMAGE',
                'media' => ['image', 'video'],
                'min_items' => 2,
                'max_items' => 10,
                'aspect' => [0.8, 1.91],
                'max_video_seconds' => 60,
                'max_image_mb' => 8,
                'max_video_mb' => 100,
            ],
            'reel' => [
                'label' => 'Reel',
                'vertical' => true,
                'vendor_media_type' => 'REELS',
                'media' => ['video'],
                'min_items' => 1,
                'max_items' => 1,
                'aspect' => null,
                'min_video_seconds' => 3,
                'max_video_seconds' => 900,
                'max_video_mb' => 1000,
            ],
            'story' => $story + ['vendor_media_type' => 'STORIES'],
        ],
    ],

    'tiktok' => [
        'label' => 'TikTok',
        'vendor' => 'tiktok',
        'vendor_prefix' => 'tiktok',
        'vendor_description' => true,
        'cover_field' => 'tiktok_cover_image_url',
        'thumb_offset_field' => 'cover_timestamp',
        'keep_ai_originals' => false,
        'hosted_connect' => true,
        'caption_limit' => 2200,
        'hashtags' => true,
        'max_hashtags' => 30,
        'hashtag_spacer' => true,
        'links_in_caption' => false,
        'link_field' => null,
        'first_comment' => true,
        'alt_text' => false,
        'text_only' => false,
        'settings' => [],
        'media_ratios' => ['original', '9:16'],
        'ai_image_ratio' => '9:16',
        'caption_fold' => ['chars' => 80, 'lines' => 2],
        'default_format' => 'video',
        'formats' => [
            'video' => [
                'label' => 'Video',
                'vertical' => true,
                'media' => ['video'],
                'min_items' => 1,
                'max_items' => 1,
                'aspect' => null,
                'min_video_seconds' => 3,
                'max_video_seconds' => 600,
                'max_video_mb' => 4000,
            ],
            'photo' => [
                'label' => 'Photo mode',
                'vertical' => true,
                'fill_screen' => '9:16',
                'media' => ['image'],
                'min_items' => 1,
                'max_items' => 35,
                'aspect' => null,
                'max_image_mb' => 20,
            ],
        ],
    ],

    'facebook' => [
        'label' => 'Facebook',
        'vendor' => 'facebook',
        'vendor_prefix' => 'facebook',
        'vendor_description' => true,
        'cover_field' => 'thumbnail_url',
        'thumb_offset_field' => null,
        'keep_ai_originals' => false,
        'hosted_connect' => true,
        'caption_limit' => 63206,
        'hashtags' => true,
        'max_hashtags' => 30,
        'hashtag_spacer' => true,
        'links_in_caption' => true,
        'link_field' => null,
        'first_comment' => true,
        'alt_text' => true,
        'text_only' => false,
        'settings' => [
            'facebook_page_id' => ['label' => 'Facebook Page ID', 'required' => false, 'help' => 'Only needed when the connected login manages more than one Page.'],
        ],
        'media_ratios' => ['original', '1:1', '4:5', '1.91:1'],
        'ai_image_ratio' => '1:1',
        'caption_fold' => ['chars' => 240, 'lines' => 5],
        'default_format' => 'post',
        'formats' => [
            'post' => [
                'label' => 'Feed post',
                'vendor_media_type' => 'POSTS',
                'vendor_media_type_video' => 'VIDEO',
                'vendor_media_type_field' => 'facebook_media_type',
                'media' => ['image', 'video'],
                'min_items' => 1,
                'max_items' => 10,
                'aspect' => null,
                'max_video_seconds' => 14400,
                'max_image_mb' => 10,
                'max_video_mb' => 4000,
            ],
            'reel' => [
                'label' => 'Reel',
                'vertical' => true,
                'vendor_media_type' => 'REELS',
                'vendor_media_type_field' => 'facebook_media_type',
                'media' => ['video'],
                'min_items' => 1,
                'max_items' => 1,
                'aspect' => [0.54, 0.58],
                'min_video_seconds' => 3,
                'max_video_seconds' => 90,
                'max_video_mb' => 1000,
            ],
            'story' => $story + ['vendor_media_type' => 'STORIES', 'vendor_media_type_field' => 'facebook_media_type'],
        ],
    ],

    'linkedin' => [
        'label' => 'LinkedIn',
        'vendor' => 'linkedin',
        'vendor_prefix' => 'linkedin',
        'vendor_description' => true,
        'cover_field' => 'thumbnail_url',
        'thumb_offset_field' => null,
        'keep_ai_originals' => true,
        'hosted_connect' => true,
        'caption_limit' => 3000,
        'hashtags' => true,
        'max_hashtags' => 5,
        'hashtag_spacer' => true,
        'links_in_caption' => true,
        'link_field' => null,
        'first_comment' => true,
        'alt_text' => true,
        'text_only' => true,
        'settings' => [
            'target_linkedin_page_id' => ['label' => 'LinkedIn company page ID', 'required' => false, 'help' => 'Post as a company page instead of the connected profile.'],
        ],
        'media_ratios' => ['original', '1:1', '4:5', '1.91:1'],
        'ai_image_ratio' => '1:1',
        'caption_fold' => ['chars' => 210, 'lines' => 3],
        'default_format' => 'post',
        'formats' => [
            'post' => [
                'label' => 'Post',
                'media' => ['image', 'video'],
                'min_items' => 0,
                'max_items' => 20,
                'aspect' => null,
                'min_video_seconds' => 3,
                'max_video_seconds' => 900,
                'max_image_mb' => 8,
                'max_video_mb' => 5000,
            ],
        ],
    ],

    'youtube' => [
        'label' => 'YouTube',
        'vendor' => 'youtube',
        'vendor_prefix' => 'youtube',
        'vendor_description' => true,
        'cover_field' => 'thumbnail_url',
        'thumb_offset_field' => null,
        'keep_ai_originals' => false,
        'hosted_connect' => true,
        'caption_limit' => 5000,
        'title_limit' => 100,
        'hashtags' => true,
        'max_hashtags' => 15,
        'hashtag_spacer' => false,
        'links_in_caption' => true,
        'link_field' => null,
        'first_comment' => true,
        'alt_text' => false,
        'text_only' => false,
        'settings' => [],
        'media_ratios' => ['original'],
        'ai_image_ratio' => '9:16',
        'caption_fold' => ['chars' => 100, 'lines' => 1],
        'default_format' => 'short',
        'formats' => [
            'short' => [
                'label' => 'Short',
                'vertical' => true,
                'media' => ['video'],
                'min_items' => 1,
                'max_items' => 1,
                'aspect' => [0.5, 1.0],
                'min_video_seconds' => 1,
                'max_video_seconds' => 180,
                'max_video_mb' => 2000,
            ],
        ],
    ],

    'google_business' => [
        'label' => 'Google Business',
        'vendor' => 'google_business',
        'vendor_prefix' => 'gbp',
        // M-0.2: Upload-Post reports Google Business at account level only.
        'post_metrics' => false,
        'vendor_description' => false,
        'cover_field' => null,
        'thumb_offset_field' => null,
        'keep_ai_originals' => false,
        'hosted_connect' => true,
        'caption_limit' => 1500,
        'hashtags' => false,
        'max_hashtags' => 0,
        'hashtag_spacer' => false,
        'links_in_caption' => false,
        'link_field' => 'gbp_cta_url',
        'first_comment' => false,
        'alt_text' => false,
        'text_only' => false,
        'settings' => [
            'gbp_location_id' => ['label' => 'Business location ID', 'required' => false, 'help' => 'Only needed when the profile has more than one location.'],
        ],
        'media_ratios' => ['original', '4:5', '1:1', '1.91:1'],
        'ai_image_ratio' => '4:5',
        'caption_fold' => ['chars' => 180, 'lines' => 4],
        'default_format' => 'update',
        'formats' => [
            'update' => [
                'label' => 'Update',
                'media' => ['image', 'video'],
                'min_items' => 1,
                'max_items' => 1,
                'aspect' => null,
                'max_video_seconds' => 30,
                'max_image_mb' => 5,
                'max_video_mb' => 75,
            ],
        ],
    ],

    'threads' => [
        'label' => 'Threads',
        'vendor' => 'threads',
        'vendor_prefix' => 'threads',
        'vendor_description' => false,
        'cover_field' => null,
        'thumb_offset_field' => null,
        'keep_ai_originals' => false,
        'hosted_connect' => true,
        'caption_limit' => 500,
        'hashtags' => true,
        'max_hashtags' => 1,
        'hashtag_spacer' => false,
        'links_in_caption' => true,
        'link_field' => null,
        'first_comment' => true,
        'alt_text' => true,
        'text_only' => true,
        'settings' => [],
        'media_ratios' => ['original', '1:1', '4:5'],
        'ai_image_ratio' => '4:5',
        'caption_fold' => ['chars' => 500, 'lines' => 10],
        'default_format' => 'post',
        'formats' => [
            'post' => [
                'label' => 'Post',
                'media' => ['image', 'video'],
                'min_items' => 0,
                'max_items' => 20,
                'aspect' => null,
                'max_video_seconds' => 300,
                'max_image_mb' => 8,
                'max_video_mb' => 1000,
            ],
        ],
    ],

    'pinterest' => [
        'label' => 'Pinterest',
        'vendor' => 'pinterest',
        'vendor_prefix' => 'pinterest',
        'vendor_description' => true,
        'cover_field' => 'pinterest_cover_image_url',
        'thumb_offset_field' => null,
        'keep_ai_originals' => false,
        'hosted_connect' => false,
        'caption_limit' => 500,
        'title_limit' => 100,
        'hashtags' => true,
        'max_hashtags' => 20,
        'hashtag_spacer' => false,
        'links_in_caption' => false,
        'link_field' => 'pinterest_link',
        'first_comment' => false,
        'alt_text' => true,
        'text_only' => false,
        'settings' => [
            'pinterest_board_id' => ['label' => 'Board ID', 'required' => true, 'help' => 'The board new Pins go to. Find it in the board’s URL or Pinterest settings.'],
        ],
        'media_ratios' => ['original', '1:1', '4:5', '9:16'],
        'ai_image_ratio' => '9:16',
        'caption_fold' => ['chars' => 100, 'lines' => 2],
        'default_format' => 'pin',
        'formats' => [
            'pin' => [
                'label' => 'Pin',
                'media' => ['image', 'video'],
                'min_items' => 1,
                'max_items' => 1,
                'aspect' => null,
                'min_video_seconds' => 4,
                'max_video_seconds' => 900,
                'max_image_mb' => 20,
                'max_video_mb' => 2000,
            ],
            'carousel' => [
                'label' => 'Carousel',
                'media' => ['image'],
                'min_items' => 2,
                'max_items' => 5,
                'aspect' => null,
                'max_image_mb' => 20,
            ],
        ],
    ],

    'x' => [
        'label' => 'X',
        'vendor' => 'x',
        'vendor_prefix' => 'x',
        'vendor_description' => false,
        'cover_field' => 'thumbnail_url',
        'thumb_offset_field' => null,
        'keep_ai_originals' => false,
        'hosted_connect' => true,
        'caption_limit' => 280,
        'hashtags' => true,
        'max_hashtags' => 2,
        'hashtag_spacer' => false,
        'links_in_caption' => true,
        'link_field' => null,
        'first_comment' => true,
        'alt_text' => true,
        'text_only' => true,
        'settings' => [],
        'media_ratios' => ['original', '1:1', '1.91:1'],
        'ai_image_ratio' => '16:9',
        'caption_fold' => ['chars' => 280, 'lines' => 12],
        'default_format' => 'post',
        'formats' => [
            'post' => [
                'label' => 'Post',
                'media' => ['image', 'video'],
                'min_items' => 0,
                'max_items' => 4,
                'aspect' => null,
                'max_video_seconds' => 140,
                'max_image_mb' => 5,
                'max_video_mb' => 512,
            ],
        ],
    ],

];
