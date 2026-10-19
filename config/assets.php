<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Asset Storage Disk
    |--------------------------------------------------------------------------
    |
    | The filesystem disk (see config/filesystems.php) used to store uploaded
    | Asset files. Step I-1.6: the private `local` disk by default, served only
    | through signed URLs (spec §10). Files already stored on the old `public`
    | disk keep working (each row remembers its disk). Switching to S3 is
    | ASSETS_DISK=s3 plus the AWS_* env vars and the flysystem S3 package; no
    | Action/Controller code needs to change.
    |
    */

    'disk' => env('ASSETS_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Signed URL lifetimes (Step I-1.6)
    |--------------------------------------------------------------------------
    |
    | Files on a non-public disk (the private `local` disk by default, or `s3` — which also needs
    | `composer require league/flysystem-aws-s3-v3`) are only reachable through signed, expiring
    | URLs. `url_ttl_minutes` covers in-app viewing; `vendor_url_ttl_minutes` covers a publishing
    | vendor fetching media by URL, which can happen well after the request was made.
    |
    */

    'url_ttl_minutes' => (int) env('ASSETS_URL_TTL_MINUTES', 60),

    'vendor_url_ttl_minutes' => (int) env('ASSETS_VENDOR_URL_TTL_MINUTES', 1440),

    /*
    |--------------------------------------------------------------------------
    | Storage Directory
    |--------------------------------------------------------------------------
    |
    | Base directory (within the configured disk) that uploaded assets are
    | stored under, namespaced per organization.
    |
    */

    'directory' => env('ASSETS_DIRECTORY', 'assets'),

    /*
    |--------------------------------------------------------------------------
    | Max Upload Size (KB)
    |--------------------------------------------------------------------------
    */

    'max_upload_size_kb' => (int) env('ASSETS_MAX_UPLOAD_SIZE_KB', 51200),

    /*
    |--------------------------------------------------------------------------
    | Max Video Upload Size (KB)
    |--------------------------------------------------------------------------
    |
    | Video files are typically much larger than images/documents, so they get
    | their own (higher) size ceiling. `StoreAssetRequest` picks this limit
    | instead of `max_upload_size_kb` whenever the uploaded file's mime type
    | starts with "video/".
    |
    */

    'max_video_upload_size_kb' => (int) env('ASSETS_MAX_VIDEO_UPLOAD_SIZE_KB', 512000),

    /*
    |--------------------------------------------------------------------------
    | Allowed Upload Extensions
    |--------------------------------------------------------------------------
    |
    | Extensions accepted by `StoreAssetRequest` for `source = upload` assets.
    | Kept intentionally broad (images, common video formats, and a handful of
    | document/archive formats) rather than image-only, since Assets can back
    | video posts (e.g. Instagram Reels/TikTok) as well as image posts.
    |
    */

    'allowed_upload_extensions' => [
        // Images
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'heic',
        // Video
        'mp4', 'mov', 'webm', 'avi', 'mkv', 'm4v',
        // Documents / other
        'pdf', 'doc', 'docx', 'ppt', 'pptx', 'zip',
    ],

    /*
    |--------------------------------------------------------------------------
    | Media probing (Step I-3.5 / M-2.4)
    |--------------------------------------------------------------------------
    |
    | `ProbeAssetJob` reads a video's size, length and codec with ffprobe and grabs a thumbnail
    | frame with ffmpeg. Without the binaries videos stay unprobed and the checklist skips the
    | length/shape checks it can't make (images are probed with GD either way).
    |
    */

    'ffprobe_path' => env('FFPROBE_PATH', 'ffprobe'),

    'ffmpeg_path' => env('FFMPEG_PATH', 'ffmpeg'),

];
