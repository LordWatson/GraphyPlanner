<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Campaign features (Campaigns enhancement C-9.4)
    |--------------------------------------------------------------------------
    |
    | Deployment defaults for the gated campaign features. An organization can override any of
    | them through `organizations.campaign_features`. Read only through
    | `App\Contracts\CampaignFeatures`; see `.junie/modules/campaigns.md`.
    |
    */
    'features' => [
        'templates' => env('CAMPAIGN_FEATURE_TEMPLATES', true),
        'ai_planner' => env('CAMPAIGN_FEATURE_AI_PLANNER', true),
        'wrap_reports' => env('CAMPAIGN_FEATURE_WRAP_REPORTS', true),
        'invoicing' => env('CAMPAIGN_FEATURE_INVOICING', true),
    ],

];
