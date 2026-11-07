<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Data export retention
    |--------------------------------------------------------------------------
    |
    | How long a finished organisation data export stays downloadable. The file is a copy of the
    | whole organisation's records, so it is deliberately short-lived: `exports:purge-expired`
    | deletes the file and marks the row expired once this many days have passed. This is the
    | retention policy — if the number changes here, the policy changes.
    |
    */

    'retention_days' => (int) env('EXPORTS_RETENTION_DAYS', 7),

    /*
    |--------------------------------------------------------------------------
    | Rows read per chunk
    |--------------------------------------------------------------------------
    |
    | Each set is streamed into the archive in chunks, so an organisation with a hundred thousand
    | posts never loads them all into memory.
    |
    */

    'chunk' => (int) env('EXPORTS_CHUNK', 500),

];
