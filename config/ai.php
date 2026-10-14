<?php

return [
    // Global AI feature enable (checked by Actions/UI; provider binding remains Null until Step 2.7)
    'enabled' => env('AI_ENABLED', false),

    // DeepSeek provider configuration (Step 2.2)
    'deepseek' => [
        'api_key' => env('DEEPSEEK_API_KEY'),
        'model' => env('DEEPSEEK_MODEL', 'deepseek-chat'),
        'base_url' => env('DEEPSEEK_BASE_URL', 'https://api.deepseek.com'),
        'timeout' => (int) env('DEEPSEEK_TIMEOUT', 15),
        'max_tokens' => (int) env('DEEPSEEK_MAX_TOKENS', 800),
    ],
];
