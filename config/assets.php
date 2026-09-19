<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Asset Storage Disk
    |--------------------------------------------------------------------------
    |
    | The filesystem disk (see config/filesystems.php) used to store uploaded
    | Asset files. Uses Laravel's local "public" disk for now (spec §10 calls
    | for S3-compatible storage via signed URLs, but that vendor isn't wired
    | up yet). The "s3" disk is already defined in config/filesystems.php —
    | switching to it later should only require setting ASSETS_DISK=s3 (plus
    | the AWS_* env vars) once Phase 1/2 needs signed-upload URLs; no
    | Action/Controller code should need to change.
    |
    */

    'disk' => env('ASSETS_DISK', 'public'),

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

];
