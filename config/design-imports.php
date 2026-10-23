<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Design & cloud imports (Step M-5.9)
    |--------------------------------------------------------------------------
    |
    | OAuth credentials per provider. A provider is only offered when it has a
    | client id and secret (`enabled` derives from that). Figma stays link-only
    | and isn't here.
    |
    */

    'canva' => [
        'enabled' => filled(env('CANVA_CLIENT_ID')) && filled(env('CANVA_CLIENT_SECRET')),
        'client_id' => env('CANVA_CLIENT_ID'),
        'client_secret' => env('CANVA_CLIENT_SECRET'),
    ],

    'google_drive' => [
        'enabled' => filled(env('GOOGLE_DRIVE_CLIENT_ID')) && filled(env('GOOGLE_DRIVE_CLIENT_SECRET')),
        'client_id' => env('GOOGLE_DRIVE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_DRIVE_CLIENT_SECRET'),
    ],

    'dropbox' => [
        'enabled' => filled(env('DROPBOX_CLIENT_ID')) && filled(env('DROPBOX_CLIENT_SECRET')),
        'client_id' => env('DROPBOX_CLIENT_ID'),
        'client_secret' => env('DROPBOX_CLIENT_SECRET'),
    ],

];
