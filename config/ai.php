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

    /*
    |--------------------------------------------------------------------------
    | Daily Briefs (Step 2.5)
    |--------------------------------------------------------------------------
    |
    | App\Actions\AI\GenerateDailyBriefsAction runs nightly (see routes/console.php) and emails
    | Owners/Strategists about the fresh brief. The quiet hours window below (in the app's local
    | timezone, config('app.timezone')) is checked right before sending — the brief itself is
    | always generated/stored on schedule, only the notification email is held back if it would
    | land inside this window, to avoid waking anyone up over a non-urgent digest.
    |
    */
    'daily_briefs' => [
        'quiet_hours_start' => env('AI_DAILY_BRIEF_QUIET_HOURS_START', 21),
        'quiet_hours_end' => env('AI_DAILY_BRIEF_QUIET_HOURS_END', 7),
    ],

    /*
    |--------------------------------------------------------------------------
    | Guardrails (Step 2.6)
    |--------------------------------------------------------------------------
    |
    | App\Services\AI\AiGuardrailService reads this list — on top of each client's
    | App\Models\BrandBrain::voice['do_nots']/hashtag_policy['never_use'] — to reject any
    | AI-generated caption/idea/trend that mentions a prohibited topic before it's ever
    | surfaced to a user. Comma-separated, case-insensitive substring matches.
    |
    */
    'guardrails' => [
        'prohibited_topics' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('AI_PROHIBITED_TOPICS', ''))
        ))),
    ],

];
