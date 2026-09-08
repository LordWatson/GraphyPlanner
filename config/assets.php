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

];
