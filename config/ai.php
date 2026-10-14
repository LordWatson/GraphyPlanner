<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Global AI feature flag (Step 2.4)
    |--------------------------------------------------------------------------
    |
    | Checked by App\Http\Controllers\AiController and the editor/dashboard UI props before
    | exposing any AI endpoints or panels. The per-org enable switch and the logic that
    | actually swaps AppServiceProvider's AiProvider::class binding to DeepSeekProvider are
    | still Step 2.7's job — until then this only gates the UI/controller surface added here,
    | while the Actions themselves keep resolving the safe NullAiProvider.
    |
    */
    'enabled' => env('AI_ENABLED', false),

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
