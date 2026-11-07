<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Team-chat delivery
    |--------------------------------------------------------------------------
    |
    | Which `App\Contracts\ChatNotifier` implementation is bound:
    |
    |   slack — posts to each organization's own incoming-webhook URL
    |            (`organizations.chat_webhook_url`). Works for Microsoft Teams too: both accept a
    |            JSON POST to a per-channel URL, and Teams renders the `text` field.
    |   null  — sends nothing, for a deployment that does not want it at all.
    |
    | `slack` is the default and is safe without any configuration: an organization that has not
    | pasted a webhook URL is simply never notified. The contract promises delivery is best-effort
    | and never throws into the caller, so a dead webhook can never fail the thing that triggered it.
    |
    */

    'chat' => env('CHAT_NOTIFIER', 'slack'),

    /*
    |--------------------------------------------------------------------------
    | Chat request timeout (seconds)
    |--------------------------------------------------------------------------
    |
    | Deliberately short. A chat message is a courtesy riding on the back of something that has
    | already happened, so it must never be what makes a request or a job slow.
    |
    */

    'chat_timeout' => (int) env('CHAT_NOTIFIER_TIMEOUT', 5),

];
