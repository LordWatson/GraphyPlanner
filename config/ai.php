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
        // Campaigns enhancement C-8.3: an estimated cost per successful campaign AI call (USD),
        // recorded on `ai_usage_logs.cost_estimate` so campaign profitability can show AI spend.
        'estimated_cost_per_call' => (float) env('DEEPSEEK_ESTIMATED_COST_PER_CALL', 0.002),
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
    | AI Image Studio (Phase 16)
    |--------------------------------------------------------------------------
    |
    | App\Contracts\ImageProvider resolves to the configured vendor only when `ai.enabled` is true
    | AND that vendor's key is set; otherwise App\Services\AI\Images\NullImageProvider renders
    | deterministic placeholders (same rule as AiProvider, Step 2.7). Sets render at the draft
    | tier; only "Make final" uses the final tier. Image jobs run on their own queue so a burst of
    | generation never delays PublishPostJob: run `php artisan queue:work --queue=ai-images`
    | alongside the default worker. Model ids/prices verified against Google's docs on
    | 2026-10-17 (see .junie/modules/ai.md).
    |
    */
    'images' => [
        'provider' => env('AI_IMAGE_PROVIDER', 'null'),

        // Optional second adapter for briefs with a headline (Step 16.12): `openai` or null.
        'text_provider' => env('AI_IMAGE_TEXT_PROVIDER'),

        // DeepSeek model used only for the art-direction brief (Step 16.5). A fast non-reasoning
        // model keeps "Generate" snappy; falls back to ai.deepseek.model when empty.
        'brief_model' => env('AI_IMAGE_BRIEF_MODEL'),
        'brief_max_tokens' => (int) env('AI_IMAGE_BRIEF_MAX_TOKENS', 1500),
        'brief_cache_ttl_hours' => (int) env('AI_IMAGE_BRIEF_CACHE_TTL_HOURS', 24),

        'timeout' => (int) env('AI_IMAGE_TIMEOUT', 90),
        'max_variants' => (int) env('AI_IMAGE_MAX_VARIANTS', 4),
        'max_references' => (int) env('AI_IMAGE_MAX_REFERENCES', 8),
        'queue' => env('AI_IMAGE_QUEUE', 'ai-images'),
        'sets_per_minute' => (int) env('AI_IMAGE_SETS_PER_MINUTE', 10),
        'prune_after_days' => (int) env('AI_IMAGE_PRUNE_AFTER_DAYS', 30),

        'gemini' => [
            'api_key' => env('GEMINI_API_KEY'),
            'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
            // Nano Banana 2 (Gemini 3.1 Flash Image): fast/cheap drafts at 1K.
            'draft_model' => env('GEMINI_IMAGE_DRAFT_MODEL', 'gemini-3.1-flash-image'),
            'draft_size' => env('GEMINI_IMAGE_DRAFT_SIZE', '1K'),
            'draft_cost' => (float) env('GEMINI_IMAGE_DRAFT_COST', 0.067),
            // Nano Banana Pro (Gemini 3 Pro Image): finals. 2K costs the same as 1K on Pro.
            'final_model' => env('GEMINI_IMAGE_FINAL_MODEL', 'gemini-3-pro-image'),
            'final_size' => env('GEMINI_IMAGE_FINAL_SIZE', '2K'),
            'final_cost' => (float) env('GEMINI_IMAGE_FINAL_COST', 0.134),
        ],

        'openai' => [
            'api_key' => env('OPENAI_API_KEY'),
            'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
            'model' => env('OPENAI_IMAGE_MODEL', 'gpt-image-2'),
            'draft_quality' => env('OPENAI_IMAGE_DRAFT_QUALITY', 'low'),
            'final_quality' => env('OPENAI_IMAGE_FINAL_QUALITY', 'high'),
            'draft_cost' => (float) env('OPENAI_IMAGE_DRAFT_COST', 0.02),
            'final_cost' => (float) env('OPENAI_IMAGE_FINAL_COST', 0.17),
        ],
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
