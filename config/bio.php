<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Custom domain target (Step GTM-3.2)
    |--------------------------------------------------------------------------
    |
    | The hostname a creator points their own domain at with a CNAME, and the value
    | `VerifyBioPageDomainAction` checks for. It defaults to the app's own host, which is right for
    | a single-server deploy; put the edge/proxy hostname here when TLS is terminated somewhere
    | else (see `ops.md`).
    |
    */

    'domain_target' => env('BIO_DOMAIN_TARGET', parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST) ?: 'localhost'),

];
