<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Correlation id (Step I-16.1 / M-1.1)
    |--------------------------------------------------------------------------
    |
    | Every request gets a correlation id: the incoming header when a proxy/load balancer already
    | set a well-formed one, otherwise a fresh UUID. It goes into Laravel's `Context`, so it's on
    | every log line, carried into every queued job the request dispatches, sent to error
    | tracking, and echoed back on the response under the same header.
    |
    */

    'correlation_header' => env('CORRELATION_ID_HEADER', 'X-Request-Id'),

    /*
    |--------------------------------------------------------------------------
    | Operational alerts
    |--------------------------------------------------------------------------
    |
    | `OpsAlerter` fires on failed `publishing` jobs and on webhook signature failures. Each alert
    | is logged at `critical` on `log_channel` (point it at `slack` to page a channel) and mailed to
    | `mail_to` when set. Alerts with the same key are throttled to one per `throttle_minutes`, so
    | a vendor outage that fails 200 jobs sends one email, not 200.
    |
    */

    'alerts' => [
        'mail_to' => env('OPS_ALERT_MAIL_TO'),
        'log_channel' => env('OPS_ALERT_LOG_CHANNEL'),
        'throttle_minutes' => (int) env('OPS_ALERT_THROTTLE_MINUTES', 10),
    ],

];
