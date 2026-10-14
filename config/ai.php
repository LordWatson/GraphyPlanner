<?php

return [

    /*
    |--------------------------------------------------------------------------
    | DeepSeek (Phase 2 AI provider, Step 2.2)
    |--------------------------------------------------------------------------
    |
    | Credentials and request tuning for App\Services\AI\DeepSeekProvider. Provider
    | selection (whether this is actually bound against App\Contracts\AiProvider,
    | vs. the safe App\Services\AI\NullAiProvider default) is handled separately in
    | Step 2.7 — this file only configures the DeepSeek client itself.
    |
    */
    'deepseek' => [
        'api_key' => env('DEEPSEEK_API_KEY'),
        'model' => env('DEEPSEEK_MODEL', 'deepseek-chat'),
        'base_url' => env('DEEPSEEK_BASE_URL', 'https://api.deepseek.com'),
        'timeout' => (int) env('DEEPSEEK_TIMEOUT', 30),
        'max_tokens' => (int) env('DEEPSEEK_MAX_TOKENS', 1024),
    ],

];
