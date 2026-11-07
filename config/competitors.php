<?php

/*
|--------------------------------------------------------------------------
| Competitor review (Step GTM-7.2) and the figures the comparison pages quote
|--------------------------------------------------------------------------
|
| `last_checked` is the single date behind every competitor figure on the marketing site. The
| comparison pages print it, and `competitors:review-due` alerts when it goes stale, so a page can
| never quietly carry a year-old price.
|
| `review_every_days` is the cadence: quarterly, as GTM-7.2 asks.
|
| **Every figure below was read from the vendor's own pricing page on `last_checked`.** Change a
| number and you change the date. Nothing here is second-hand, and nothing claims a competitor
| *lacks* something — only what they charge and what they say they include.
|
*/

return [

    'last_checked' => env('COMPETITORS_LAST_CHECKED', '2026-11-07'),

    'review_every_days' => (int) env('COMPETITORS_REVIEW_EVERY_DAYS', 90),

    /*
    | One row per competitor. `price` is what their own page says, verbatim enough to be checked.
    | `strengths` is what they genuinely do better — a comparison page that pretends otherwise
    | gets found out, and the honest version is the one that converts.
    */
    'rivals' => [

        'planoly' => [
            'name' => 'Planoly',
            'price' => '$14/month (Starter, billed annually)',
            'positioning' => 'Creator-first grid planning, link in bio and selling, bundled cheap.',
            'strengths' => [
                'Cheaper than Creator Pro, and the bundle is wide: grid, link in bio, zero-fee selling, physical merch, Instagram auto-DM and a CRM on every plan.',
                'A free mobile-only tier (10 uploads a month), which is how most creators meet this category.',
                'Instagram auto-DM from a comment keyword, which Alima does not have.',
            ],
            'ours' => [
                'Brand deals tracked end to end: who owes what, what has gone out, and an invoice raised from the deal.',
                'Disclosure that actually blocks a publish — an undisclosed paid post cannot go out, per channel.',
                'A media kit built from your real numbers, with a sponsor share-preview.',
                'Nine networks with approvals and a client portal, not just the creator surfaces.',
            ],
        ],

        'metricool' => [
            'name' => 'Metricool',
            'price' => 'from €16/month (Starter)',
            'positioning' => 'Analytics-led scheduling with competitor tracking and ad management.',
            'strengths' => [
                'Tracks up to 100 competitor profiles on its paid plans; Alima tracks a handful, Instagram only.',
                'Manages paid ads. Alima reports on spend and never changes it, by design.',
                'Cheaper per seat at the entry tier.',
            ],
            'ours' => [
                'Client approvals, a client portal and client invoicing — Metricool is a tool, not an agency workflow.',
                'AI grounded in a per-brand Brand Brain rather than generic generation.',
                'An agent interface (MCP) with org-scoped isolation and an audit trail.',
            ],
        ],

        'cloud-campaign' => [
            'name' => 'Cloud Campaign',
            'price' => '$199/month (Team) and $299/month (Agency)',
            'positioning' => 'White-label agency scheduling.',
            'strengths' => [
                'Long-established white label with its own domain and sender setup.',
                'Bulk content library and recycling built for high-volume agencies.',
            ],
            'ours' => [
                'White labelling on every agency tier from £49/month, not from $199.',
                'Campaigns end to end: brief, approve, report and bill in one place.',
                'Client reports built from the numbers rather than assembled by hand.',
            ],
        ],

        'later' => [
            'name' => 'Later',
            'price' => 'from $25/month (Starter); Meta Ads analytics on Scale',
            'positioning' => 'Visual-first scheduling with influencer tooling.',
            'strengths' => [
                'Influencer marketplace and campaign tooling Alima has no equivalent for.',
                'Strong visual planning and a long track record on Instagram.',
            ],
            'ours' => [
                'A creator store that pays the creator directly at a 0% fee.',
                'Approvals and a client portal on every agency tier.',
                'Brand deals, disclosure enforcement and a real-numbers media kit.',
            ],
        ],

    ],

];
