<?php

namespace App\Services\AI;

use App\Contracts\AiProvider;
use App\Contracts\CampaignAiProvider;
use App\Contracts\CaptionRepurposer;
use App\Contracts\GridAiProvider;
use App\Contracts\LocalIdeaProvider;
use App\Enums\Platform;
use App\Models\Campaign;
use App\Models\Client;
use App\Models\GridPlan;
use App\Models\Post;
use App\Support\AI\AiBrandProfileDraft;
use App\Support\AI\AiCaptionSuggestion;
use App\Support\AI\AiCaptionSuggestions;
use App\Support\AI\AiPostIdea;
use App\Support\AI\AiPostIdeas;
use App\Support\AI\AiTrend;
use App\Support\AI\AiTrends;
use App\Support\AI\BrandProfileInput;
use App\Support\AI\Campaigns\AiCampaignBriefDraft;
use App\Support\AI\Campaigns\AiCampaignWrapDraft;
use App\Support\AI\Campaigns\AiDeliverableSuggestion;
use App\Support\AI\ClientContextBuilder;
use App\Support\AI\IdeaContext;
use App\Support\AI\Images\AiImageBrief;
use App\Support\AI\Images\BrandImageBriefFactory;
use App\Support\AI\Images\ImageBriefInput;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * DeepSeek-backed implementation of App\Contracts\AiProvider (Step 2.2). Builds a system
 * prompt from the client's company context (profile, full Brand Brain, current campaigns — see
 * ClientContextBuilder) plus platform caption-length constraints, and asks DeepSeek's chat
 * completions endpoint for strict JSON matching each method's DTO shape. Not bound by default — provider selection (Step 2.7)
 * decides when this (vs. App\Services\AI\NullAiProvider) is actually resolved against the
 * AiProvider contract.
 */
class DeepSeekProvider implements AiProvider, CampaignAiProvider, CaptionRepurposer, GridAiProvider, LocalIdeaProvider
{
    /**
     * Schema is invalid/unparsable this many times before giving up and logging a failure.
     */
    private const MAX_ATTEMPTS = 2;

    /**
     * Upper bound when a truncated (`finish_reason = length`) attempt doubles max_tokens for its retry.
     */
    private const MAX_TOKENS_CEILING = 16000;

    /**
     * How many ideas/trends each request asks for (and keeps, if the model overshoots). Kept
     * small so a reasoning model's answer stays well inside max_tokens and responses stay fast.
     */
    private const IDEAS_PER_REQUEST = 3;

    private const TRENDS_PER_REQUEST = 3;

    public function __construct(
        private readonly AiGuardrailService $guardrail = new AiGuardrailService,
        private readonly ClientContextBuilder $context = new ClientContextBuilder,
        private readonly BrandImageBriefFactory $briefs = new BrandImageBriefFactory,
    ) {}

    public function suggestCaptions(Post $post): AiCaptionSuggestions
    {
        $client = $post->client;

        $payload = $this->requestStructured(
            method: 'suggestCaptions',
            systemPrompt: $this->buildSystemPrompt($client),
            userPrompt: $this->buildCaptionsUserPrompt($post),
            validator: fn (array $data): ?string => $this->shapeError($data, 'suggestions', ['caption']),
            context: ['org_id' => $client?->org_id, 'client_id' => $client?->id, 'post_id' => $post->id],
        );

        if ($payload === null) {
            return new AiCaptionSuggestions;
        }

        $suggestions = collect($payload['suggestions'] ?? [])
            ->map(fn (array $item): AiCaptionSuggestion => new AiCaptionSuggestion(
                caption: (string) ($item['caption'] ?? ''),
                hashtags: array_values(array_map('strval', $item['hashtags'] ?? [])),
                altText: isset($item['altText']) ? (string) $item['altText'] : null,
                firstComment: isset($item['firstComment']) ? (string) $item['firstComment'] : null,
            ))
            ->values()
            ->all();

        return new AiCaptionSuggestions($suggestions);
    }

    public function recommendPosts(Client $client): AiPostIdeas
    {
        $payload = $this->requestStructured(
            method: 'recommendPosts',
            systemPrompt: $this->buildSystemPrompt($client),
            userPrompt: $this->buildRecommendPostsUserPrompt(),
            validator: fn (array $data): ?string => $this->shapeError($data, 'ideas', ['title', 'description']),
            context: ['org_id' => $client->org_id, 'client_id' => $client->id],
        );

        if ($payload === null) {
            return new AiPostIdeas;
        }

        $ideas = collect((array) ($payload['ideas'] ?? []))
            ->take(self::IDEAS_PER_REQUEST)
            ->map(fn (array $item): AiPostIdea => new AiPostIdea(
                title: (string) ($item['title'] ?? ''),
                description: (string) ($item['description'] ?? ''),
                platforms: array_values(array_map('strval', $item['platforms'] ?? [])),
                suggestedCaption: isset($item['suggestedCaption']) ? (string) $item['suggestedCaption'] : null,
                suggestedPostAt: isset($item['suggestedPostAt']) ? (string) $item['suggestedPostAt'] : null,
            ))
            ->values()
            ->all();

        return new AiPostIdeas($ideas);
    }

    /**
     * Step B-6.1: the local/seasonal idea engine. The same grounded system prompt as
     * {@see recommendPosts()}, with the brand's place-and-moment context ({@see IdeaContext}:
     * location, season, upcoming local dates, recent posts to avoid) added to the user prompt so the
     * ideas feel like this brand, this week. Falls back to empty ideas on failure.
     */
    public function recommendLocalPosts(Client $client, IdeaContext $context): AiPostIdeas
    {
        $payload = $this->requestStructured(
            method: 'recommendLocalPosts',
            systemPrompt: $this->buildSystemPrompt($client),
            userPrompt: $this->buildRecommendLocalPostsUserPrompt($context),
            validator: fn (array $data): ?string => $this->shapeError($data, 'ideas', ['title', 'description']),
            context: ['org_id' => $client->org_id, 'client_id' => $client->id],
        );

        if ($payload === null) {
            return new AiPostIdeas;
        }

        $ideas = collect((array) ($payload['ideas'] ?? []))
            ->take(self::IDEAS_PER_REQUEST)
            ->map(fn (array $item): AiPostIdea => new AiPostIdea(
                title: (string) ($item['title'] ?? ''),
                description: (string) ($item['description'] ?? ''),
                platforms: array_values(array_map('strval', $item['platforms'] ?? [])),
                suggestedCaption: isset($item['suggestedCaption']) ? (string) $item['suggestedCaption'] : null,
                suggestedPostAt: isset($item['suggestedPostAt']) ? (string) $item['suggestedPostAt'] : null,
            ))
            ->values()
            ->all();

        return new AiPostIdeas($ideas);
    }

    /**
     * Step B-6.4: rewrite the post's master caption natively for each platform. Strict JSON, the
     * same grounded prompt + one-retry path. Caption-length/hashtag limits are stated in the prompt
     * and enforced again by the Action, so a variant can never exceed a platform's cap.
     *
     * @param  array<int, string>  $platforms
     * @return array<string, array{caption: string, hashtags: list<string>, firstComment: string|null}>
     */
    public function repurpose(Post $post, array $platforms): array
    {
        $client = $post->client;
        $platforms = array_values(array_unique($platforms));

        if ($platforms === [] || $client === null) {
            return [];
        }

        $payload = $this->requestStructured(
            method: 'repurpose',
            systemPrompt: $this->buildSystemPrompt($client),
            userPrompt: $this->buildRepurposeUserPrompt($post, $platforms),
            validator: fn (array $data): ?string => $this->shapeError($data, 'variants', ['platform', 'caption']),
            context: ['org_id' => $post->org_id, 'client_id' => $post->client_id, 'post_id' => $post->id],
            maxTokens: max(2000, (int) config('ai.deepseek.max_tokens', 1024)),
        );

        if ($payload === null) {
            return [];
        }

        $variants = [];

        foreach ((array) ($payload['variants'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $platform = strtolower(trim((string) ($item['platform'] ?? '')));

            if (! in_array($platform, $platforms, true) || isset($variants[$platform])) {
                continue;
            }

            $variants[$platform] = [
                'caption' => trim((string) ($item['caption'] ?? '')),
                'hashtags' => array_values(array_map('strval', is_array($item['hashtags'] ?? null) ? $item['hashtags'] : [])),
                'firstComment' => isset($item['firstComment']) && is_string($item['firstComment']) ? $item['firstComment'] : null,
            ];
        }

        return $variants;
    }

    public function discoverTrends(Client $client, array $platforms): AiTrends
    {
        $payload = $this->requestStructured(
            method: 'discoverTrends',
            systemPrompt: $this->buildSystemPrompt($client),
            userPrompt: $this->buildDiscoverTrendsUserPrompt($client, $platforms),
            validator: fn (array $data): ?string => $this->shapeError($data, 'trends', ['title', 'summary']),
            context: ['org_id' => $client->org_id, 'client_id' => $client->id, 'platforms' => $platforms],
        );

        if ($payload === null) {
            return new AiTrends;
        }

        $trends = collect($payload['trends'] ?? [])
            ->take(self::TRENDS_PER_REQUEST)
            ->map(fn (array $item): AiTrend => new AiTrend(
                title: (string) ($item['title'] ?? ''),
                summary: (string) ($item['summary'] ?? ''),
                platforms: array_values(array_map('strval', $item['platforms'] ?? [])),
                confidence: (float) ($item['confidence'] ?? 0.0),
            ))
            ->values()
            ->all();

        return new AiTrends($trends);
    }

    /**
     * Step 16.5: DeepSeek as art director. Writes the creative fields of an image brief
     * (subject, composition, mood, style) in strict JSON; the palette, references, headline and
     * do-nots are then applied deterministically by BrandImageBriefFactory::finalize(), never left
     * to the model. Uses the (fast) `ai.images.brief_model` when configured. Falls back to the
     * brand-derived brief if DeepSeek fails, so "Generate" never dead-ends on the text model.
     */
    public function draftImageBrief(Client $client, ImageBriefInput $input): AiImageBrief
    {
        $payload = $this->requestStructured(
            method: 'draftImageBrief',
            systemPrompt: $this->buildSystemPrompt($client),
            userPrompt: $this->buildImageBriefUserPrompt($input),
            validator: fn (array $data): ?string => $this->imageBriefShapeError($data),
            context: ['org_id' => $client->org_id, 'client_id' => $client->id],
            model: filled(config('ai.images.brief_model')) ? (string) config('ai.images.brief_model') : null,
            maxTokens: (int) config('ai.images.brief_max_tokens', 1500),
        );

        if ($payload === null) {
            return $this->briefs->defaults($client, $input);
        }

        $brief = AiImageBrief::fromArray([
            'subject' => $payload['subject'],
            'composition' => $payload['composition'] ?? '',
            'mood' => $payload['mood'] ?? '',
            'style' => $payload['style'] ?? '',
            'avoid' => $payload['avoid'] ?? [],
        ]);

        return $this->briefs->finalize($client, $brief->with(['subject' => $this->briefs->stripHashtags($brief->subject)]), $input);
    }

    /**
     * Step B-2.4: draft a business/creator's Brand Brain from the plain facts they gave onboarding.
     * No `Client` exists yet, so the system prompt carries no company context; everything the model
     * grounds on is in the user prompt. Falls back to an empty draft if DeepSeek fails, so the
     * wizard never dead-ends.
     */
    public function draftBrandProfile(BrandProfileInput $input): AiBrandProfileDraft
    {
        $model = (string) config('ai.deepseek.model', 'deepseek-chat');

        $payload = $this->requestStructured(
            method: 'draftBrandProfile',
            systemPrompt: $this->buildBrandProfileSystemPrompt(),
            userPrompt: $this->buildBrandProfileUserPrompt($input),
            validator: fn (array $data): ?string => is_array($data['voice'] ?? null) ? null : 'voice must be an object',
            context: ['brand_name' => $input->name],
            maxTokens: max(2000, (int) config('ai.deepseek.max_tokens', 1024)),
        );

        if ($payload === null) {
            return AiBrandProfileDraft::empty();
        }

        return AiBrandProfileDraft::fromArray($payload, succeeded: true, provider: 'deepseek', model: $model);
    }

    /**
     * Step C-2.2: a suggested campaign brief from the campaign's name, goal, objective and dates,
     * grounded in the client's Brand Brain (system prompt). Suggestion only.
     */
    public function draftCampaignBrief(Campaign $campaign): AiCampaignBriefDraft
    {
        $payload = $this->requestStructured(
            method: 'draftCampaignBrief',
            systemPrompt: $this->buildSystemPrompt($campaign->client),
            userPrompt: $this->buildCampaignBriefUserPrompt($campaign),
            validator: fn (array $data): ?string => is_string($data['background'] ?? null) ? null : 'background must be a string',
            context: ['org_id' => $campaign->org_id, 'client_id' => $campaign->client_id, 'campaign_id' => $campaign->id],
            maxTokens: max(2000, (int) config('ai.deepseek.max_tokens', 1024)),
        );

        return $payload === null ? new AiCampaignBriefDraft : AiCampaignBriefDraft::fromArray($payload);
    }

    /**
     * Step C-3.3: suggested deliverables across the campaign, one per item, each tied to a phase,
     * a platform from the allowed list, a pillar and a date inside the campaign.
     */
    public function planCampaign(Campaign $campaign, array $input): array
    {
        $payload = $this->requestStructured(
            method: 'planCampaign',
            systemPrompt: $this->buildSystemPrompt($campaign->client),
            userPrompt: $this->buildPlanCampaignUserPrompt($campaign, $input),
            validator: fn (array $data): ?string => $this->shapeError($data, 'deliverables', ['title', 'platform', 'date']),
            context: ['org_id' => $campaign->org_id, 'client_id' => $campaign->client_id, 'campaign_id' => $campaign->id],
            maxTokens: max(4000, (int) config('ai.deepseek.max_tokens', 1024)),
        );

        if ($payload === null) {
            return [];
        }

        $phaseIds = array_column($input['phases'], 'id');

        return array_values(array_map(fn (array $item) => new AiDeliverableSuggestion(
            title: mb_substr(trim((string) $item['title']), 0, 255),
            platform: strtolower(trim((string) $item['platform'])),
            format: isset($item['format']) && is_string($item['format']) ? mb_substr($item['format'], 0, 50) : null,
            pillar: isset($item['pillar']) && is_string($item['pillar']) ? mb_substr($item['pillar'], 0, 100) : null,
            phaseId: isset($item['phase_id']) && in_array((int) $item['phase_id'], $phaseIds, true) ? (int) $item['phase_id'] : null,
            date: (string) $item['date'],
            time: isset($item['time']) && is_string($item['time']) && preg_match('/^\d{2}:\d{2}$/', $item['time']) ? $item['time'] : null,
            angle: isset($item['angle']) && is_string($item['angle']) ? $item['angle'] : null,
        ), array_slice($payload['deliverables'], 0, $input['max'])));
    }

    /**
     * Step C-7.3: wrap report commentary from the KPI snapshot, the brief's success measures and
     * the top posts. Honest about misses ("We missed the target. Here's the fix.").
     */
    public function draftCampaignWrap(Campaign $campaign, array $facts): AiCampaignWrapDraft
    {
        $payload = $this->requestStructured(
            method: 'draftCampaignWrap',
            systemPrompt: $this->buildSystemPrompt($campaign->client),
            userPrompt: $this->buildCampaignWrapUserPrompt($campaign, $facts),
            validator: fn (array $data): ?string => is_string($data['summary'] ?? null) ? null : 'summary must be a string',
            context: ['org_id' => $campaign->org_id, 'client_id' => $campaign->client_id, 'campaign_id' => $campaign->id],
            maxTokens: max(2000, (int) config('ai.deepseek.max_tokens', 1024)),
        );

        return $payload === null ? new AiCampaignWrapDraft : AiCampaignWrapDraft::fromArray($payload);
    }

    /**
     * Step 18.12: two caption ideas per grid tile, in the brand voice. The grid is about the
     * look, so these are starting points the team picks from ("Use") — never applied here.
     */
    public function suggestTileCaptions(GridPlan $plan, array $tiles): array
    {
        $plan->loadMissing('client');
        $lines = [
            'Suggest 2 short Instagram caption ideas for each tile of this planned profile grid, in the brand voice.',
            'Each idea is a starting point for a human; keep it under 300 characters, no hashtags.',
            'Tiles (position 1 is top-left, the newest post):',
        ];

        foreach ($tiles as $tile) {
            $lines[] = "- tile_id {$tile['tile_id']} (position {$tile['position']}, {$tile['kind']}): ".$this->guardrail->scrub($tile['description']);
        }

        $lines[] = '';
        $lines[] = 'Respond with JSON: {"tiles": [{"tile_id": number, "captions": string[]}]}';

        $payload = $this->requestStructured(
            method: 'suggestTileCaptions',
            systemPrompt: $this->buildSystemPrompt($plan->client),
            userPrompt: implode("\n", $lines),
            validator: fn (array $data): ?string => is_array($data['tiles'] ?? null) ? null : 'tiles must be an array',
            context: ['org_id' => $plan->org_id, 'client_id' => $plan->client_id, 'grid_plan_id' => $plan->id],
            maxTokens: max(2000, (int) config('ai.deepseek.max_tokens', 1024)),
        );

        if ($payload === null) {
            return [];
        }

        $known = array_column($tiles, 'tile_id');
        $result = [];

        foreach ((array) $payload['tiles'] as $row) {
            $id = is_array($row) ? (int) ($row['tile_id'] ?? 0) : 0;

            if (! in_array($id, $known, true)) {
                continue;
            }

            $result[$id] = array_values(array_slice(array_filter(array_map(
                fn ($caption) => is_string($caption) ? mb_substr(trim($caption), 0, Platform::Instagram->captionLimit()) : '',
                (array) ($row['captions'] ?? []),
            ), fn (string $caption) => $caption !== ''), 0, 2));
        }

        return $result;
    }

    /**
     * Step 18.12: a few plain notes on the grid's balance against the Brand Brain's palette and
     * pillars. Suggestions only; the team reorders by hand.
     */
    public function checkGridBalance(GridPlan $plan, array $tiles): array
    {
        $plan->loadMissing('client');
        $lines = [
            'Review the balance of this planned Instagram profile grid (3 tiles per row, position 1 top-left).',
            'Look for repetition (similar shots side by side or in a row), colour runs that clash or crowd, and the mix of content pillars.',
            'Compare against the brand palette and pillars in the company context. Give at most 5 short, specific notes a designer can act on, naming tiles by number, e.g. "Three product shots in a row on row 2. Try moving tile 5 up."',
            'If the grid already looks balanced, say so in one note.',
            'Tiles:',
        ];

        foreach ($tiles as $tile) {
            $lines[] = "- tile {$tile['position']} (row {$tile['row']}, {$tile['kind']}; colours: ".implode(', ', $tile['colours']).'): '.$this->guardrail->scrub($tile['description']);
        }

        $lines[] = '';
        $lines[] = 'Respond with JSON: {"notes": string[]}';

        $payload = $this->requestStructured(
            method: 'checkGridBalance',
            systemPrompt: $this->buildSystemPrompt($plan->client),
            userPrompt: implode("\n", $lines),
            validator: fn (array $data): ?string => is_array($data['notes'] ?? null) ? null : 'notes must be an array',
            context: ['org_id' => $plan->org_id, 'client_id' => $plan->client_id, 'grid_plan_id' => $plan->id],
        );

        if ($payload === null) {
            return [];
        }

        return array_values(array_slice(array_filter(array_map(
            fn ($note) => is_string($note) ? mb_substr(trim($note), 0, 300) : '',
            (array) $payload['notes'],
        ), fn (string $note) => $note !== ''), 0, 5));
    }

    private function buildCampaignBriefUserPrompt(Campaign $campaign): string
    {
        $lines = [
            'Draft a campaign brief for the studio to review with its client.',
            'Be specific to this brand and campaign; short sentences; no jargon; British English.',
            'Campaign: '.$this->guardrail->scrub($this->context->describeCampaign($campaign)),
        ];

        if ($campaign->objective !== null) {
            $lines[] = 'Objective: '.$campaign->objective->label();
        }

        if (! empty($campaign->platforms)) {
            $lines[] = 'Intended platforms: '.implode(', ', $campaign->platforms);
        }

        $lines[] = '';
        $lines[] = 'Respond with JSON: {"background": string, "objective_detail": string, "audience": string, "key_messages": string[] (3-5), "offer": string, "call_to_action": string (max 8 words), "mandatories": string[], "avoid": string[], "deliverables_summary": string, "success_measures": string}';

        return implode("\n", $lines);
    }

    /**
     * @param  array{platforms: list<string>, phases: list<array{id: int, name: string, starts_on: string|null, ends_on: string|null}>, pillars: list<string>, start: string, end: string, max: int}  $input
     */
    private function buildPlanCampaignUserPrompt(Campaign $campaign, array $input): string
    {
        $lines = [
            "Plan up to {$input['max']} social media deliverables for this campaign, spread sensibly across its phases.",
            "Every date must be between {$input['start']} and {$input['end']} (YYYY-MM-DD). Never on a platform outside this list: ".implode(', ', $input['platforms']).'.',
            'Campaign: '.$this->guardrail->scrub($this->context->describeCampaign($campaign)),
        ];

        if (($brief = $this->context->describeCampaignBrief($campaign)) !== '') {
            $lines[] = $brief;
        }

        if ($input['phases'] !== []) {
            $lines[] = 'Phases (use phase_id):';

            foreach ($input['phases'] as $phase) {
                $lines[] = "- {$phase['id']}: {$phase['name']} ".($phase['starts_on'] ?? '?').' → '.($phase['ends_on'] ?? '?');
            }
        }

        if ($input['pillars'] !== []) {
            $lines[] = 'Content pillars: '.implode(', ', $input['pillars']);
        }

        $lines[] = '';
        $lines[] = 'Respond with JSON: {"deliverables": [{"title": string (short working title), "platform": string, "format": string|null (e.g. reel, carousel, story, static), "pillar": string|null, "phase_id": number|null, "date": "YYYY-MM-DD", "time": "HH:MM"|null, "angle": string (one line)}]}';

        return implode("\n", $lines);
    }

    /**
     * @param  array{kpis: list<array<string, mixed>>, top_posts: list<array<string, mixed>>, published: int, success_measures: string|null}  $facts
     */
    private function buildCampaignWrapUserPrompt(Campaign $campaign, array $facts): string
    {
        $lines = [
            'Write the commentary for this campaign’s wrap report, for the client.',
            'Lead with what worked. Be honest about misses and say what we will change. Short sentences. No jargon.',
            'Campaign: '.$this->guardrail->scrub($this->context->describeCampaign($campaign)),
            "Posts published: {$facts['published']}",
        ];

        if (filled($facts['success_measures'])) {
            $lines[] = 'Success measures agreed in the brief: '.$this->guardrail->scrub((string) $facts['success_measures']);
        }

        foreach ($facts['kpis'] as $kpi) {
            $lines[] = '- KPI '.($kpi['label'] ?? '').': target '.($kpi['target'] ?? '?').', actual '.($kpi['available'] ?? false ? ($kpi['actual'] ?? 'n/a') : 'not tracked yet');
        }

        foreach ($facts['top_posts'] as $post) {
            $lines[] = '- Top post: '.$this->guardrail->scrub((string) ($post['caption'] ?? ''));
        }

        $lines[] = '';
        $lines[] = 'Respond with JSON: {"summary": string (2-3 sentences), "highlights": string[] (3-5), "learnings": string, "next_steps": string}';

        return implode("\n", $lines);
    }

    private function buildBrandProfileSystemPrompt(): string
    {
        return implode("\n", [
            'You are a brand strategist helping a small business or creator set up their social media brand profile in Alima Planner.',
            'They have just signed up and given you a few plain facts. Draft a concise, practical brand profile they can accept or tweak.',
            'Write in plain, warm, second-person British English. Be specific to the facts given; never invent products, prices, awards or locations that were not provided.',
            'Always respond with a single, strictly valid JSON object matching the schema in the user message — no prose, no markdown fences.',
        ]);
    }

    private function buildBrandProfileUserPrompt(BrandProfileInput $input): string
    {
        $lines = [
            'Draft a brand profile from these facts:',
            '- Brand name: '.$this->guardrail->scrub($input->name),
        ];

        if (filled($input->oneLiner)) {
            $lines[] = '- What they do: '.$this->guardrail->scrub((string) $input->oneLiner);
        }

        if (filled($input->location)) {
            $lines[] = '- Location: '.$this->guardrail->scrub((string) $input->location);
        }

        if (filled($input->websiteText)) {
            $lines[] = '- Text from their website: '.$this->guardrail->scrub((string) $input->websiteText);
        }

        if (filled($input->bioText)) {
            $lines[] = '- Their social bio: '.$this->guardrail->scrub((string) $input->bioText);
        }

        $lines[] = '';
        $lines[] = 'Keep every list to 3-5 short items. Content pillars are the 3-5 recurring themes they should post about.';
        $lines[] = 'Respond with JSON: {"voice": {"tone": string, "personality": string, "do_nots": string[]}, "audience": {"description": string, "demographics": string, "pain_points": string[]}, "offer": {"value_proposition": string, "key_products": string[], "pricing_notes": string|null}, "content_pillars": string[]}';

        return implode("\n", $lines);
    }

    private function buildImageBriefUserPrompt(ImageBriefInput $input): string
    {
        $lines = [
            'Act as the art director for one social media image for this brand.',
            'Write a visual brief a professional photographer or illustrator could shoot from: concrete, visual, specific to this brand and its imagery style.',
            'Rules:',
            '- Describe only what is seen: setting, subject, props, people (generic, never real public figures), light, angle.',
            '- Never include hashtags, @handles, URLs, third-party brands or logos.',
            '- Never put words or text in the image; the caption carries the words.',
            '- Respect every "Do not" in the Brand Brain and match the Visual section (imagery style, palette).',
            '',
            'The post this image serves:',
            '- Title: '.$this->guardrail->scrub($input->title),
        ];

        if (filled($input->description)) {
            $lines[] = '- Idea: '.$this->guardrail->scrub((string) $input->description);
        }

        if (filled($input->caption)) {
            $lines[] = '- Caption: '.$this->guardrail->scrub($this->briefs->stripHashtags((string) $input->caption));
        }

        if (filled($input->campaign)) {
            $lines[] = '- Campaign: '.$this->guardrail->scrub((string) $input->campaign);
        }

        if ($input->platforms !== []) {
            $lines[] = '- Platforms: '.implode(', ', $input->platforms);
        }

        $lines[] = '';
        $lines[] = 'Respond with JSON: {"subject": string (1-2 sentences), "composition": string, "mood": string, "style": string, "avoid": string[] (visual things to keep out, max 5)}';

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function imageBriefShapeError(array $data): ?string
    {
        foreach (['subject', 'composition', 'mood', 'style'] as $field) {
            if (! isset($data[$field]) || ! is_string($data[$field])) {
                return "{$field} must be a string, got ".get_debug_type($data[$field] ?? null);
            }
        }

        if (trim($data['subject']) === '') {
            return 'subject must not be empty';
        }

        if (isset($data['avoid']) && ! is_array($data['avoid'])) {
            return 'avoid must be an array, got '.get_debug_type($data['avoid']);
        }

        return null;
    }

    /**
     * Build the shared system prompt: the client's company context (profile, Brand Brain,
     * current campaigns) from ClientContextBuilder, explicit rules telling the model to ground
     * every result in it, plus platform caption-length constraints so every method stays
     * on-brand and platform-aware.
     */
    private function buildSystemPrompt(?Client $client): string
    {
        $lines = [
            'You are an expert social media strategist embedded in Alima Planner, a content planning tool.',
            'You never auto-post; you only propose ideas, captions, hashtags, alt text, and posting times for a human to review.',
            'Always respond with a single, strictly valid JSON object matching the schema given in the user message — no prose, no markdown fences.',
        ];

        if ($client !== null) {
            $lines[] = '';
            $lines[] = 'Ground everything you propose in the company context below — generic advice that could apply to any brand is a failure:';
            $lines[] = '- Write in the Brand Brain voice (tone and personality) and never do anything listed under "Do not".';
            $lines[] = '- Speak to the described audience and their pain points, and reference the offer (value proposition, key products/services) where it fits naturally.';
            $lines[] = '- Tie each idea or trend angle to one of the content pillars and, where relevant, a current campaign.';
            $lines[] = '- Follow the hashtag policy: include every "Always use" hashtag in hashtag suggestions and never suggest a "Never use" one.';
            $lines[] = '- Write captions in the primary content language when one is given.';
            $lines[] = '';
            $lines[] = $this->context->build($client);
        }

        $lines[] = '';
        $lines[] = 'Platform caption limits (characters): '.collect(Platform::cases())
            ->map(fn ($platform) => $platform->label().'='.$platform->captionLimit())
            ->implode(', ');

        return implode("\n", $lines);
    }

    private function buildCaptionsUserPrompt(Post $post): string
    {
        $lines = [
            'Suggest 3 caption options for this post, each written in the brand voice from the Brand Brain.',
            'Current draft caption: '.$this->guardrail->scrub($post->master_caption ?: '(none yet)'),
            'Target platforms: '.implode(', ', array_map(fn ($platform) => $platform->label(), $post->targetPlatforms())),
        ];

        if ($post->campaign !== null) {
            $lines[] = 'Campaign this post belongs to: '.$this->guardrail->scrub($this->context->describeCampaign($post->campaign));

            // Step C-2.1: a campaign post follows its brief.
            if (($brief = $this->context->describeCampaignBrief($post->campaign)) !== '') {
                $lines[] = 'Follow this campaign brief:';
                $lines[] = $brief;
            }
        }

        if (! empty($post->hashtags)) {
            $lines[] = 'Existing hashtags: '.implode(', ', $post->hashtags);
        }

        $lines[] = '';
        $lines[] = 'Respond with JSON: {"suggestions": [{"caption": string, "hashtags": string[], "altText": string|null, "firstComment": string|null}]}';

        return implode("\n", $lines);
    }

    private function buildRecommendPostsUserPrompt(): string
    {
        $lines = [
            'Recommend exactly '.self::IDEAS_PER_REQUEST.' fresh post ideas for this client, tied to their current campaigns and content pillars.',
            'Each description must name the content pillar it serves and the audience pain point or offer it speaks to.',
            '',
            'Respond with JSON: {"ideas": [{"title": string, "description": string, "platforms": string[], "suggestedCaption": string|null, "suggestedPostAt": string|null}]}',
        ];

        return implode("\n", $lines);
    }

    private function buildRecommendLocalPostsUserPrompt(IdeaContext $context): string
    {
        $lines = [
            'Recommend exactly '.self::IDEAS_PER_REQUEST.' fresh post ideas for this brand for the days ahead.',
            'Each description must name the content pillar it serves and the audience pain point or offer it speaks to.',
            'Use the local & seasonal context below: where it fits the brand naturally, tie an idea to an upcoming date or the season — never force a tenuous link, and never repeat a recently covered angle.',
            '',
            $this->guardrail->scrub($context->toPromptBlock()),
            '',
            'Respond with JSON: {"ideas": [{"title": string, "description": string, "platforms": string[], "suggestedCaption": string|null, "suggestedPostAt": string|null}]}',
        ];

        return implode("\n", $lines);
    }

    /**
     * @param  array<int, string>  $platforms
     */
    private function buildRepurposeUserPrompt(Post $post, array $platforms): string
    {
        $lines = [
            'Rewrite this post natively for each platform below — do not just copy the same text. Keep the meaning and the brand voice, but suit each platform\'s format and audience.',
            'Guidance: TikTok/Reels — a punchy first-line hook, then a very short caption; Instagram — a warm caption with relevant hashtags; LinkedIn — a longer, professional, value-led post with few or no hashtags; Facebook — conversational, medium length; X — one tight line.',
            'Stay within each platform\'s caption limit (including hashtags). Never invent facts, offers or prices not in the original.',
            'Original caption: '.$this->guardrail->scrub($post->master_caption ?: '(none — write from the brand context)'),
        ];

        if (! empty($post->hashtags)) {
            $lines[] = 'Existing hashtags: '.implode(', ', $post->hashtags);
        }

        $lines[] = 'Platforms and their caption limits (characters) / hashtag norms:';

        foreach ($platforms as $value) {
            $platform = Platform::tryFrom($value);

            if ($platform !== null) {
                $lines[] = "- {$platform->value}: limit {$platform->captionLimit()}, up to {$platform->maxHashtags()} hashtags".($platform->allowsLinksInCaption() ? '' : ', links in the caption are not clickable');
            }
        }

        $lines[] = '';
        $lines[] = 'Respond with JSON: {"variants": [{"platform": string (one of the values above), "caption": string, "hashtags": string[], "firstComment": string|null}]}';

        return implode("\n", $lines);
    }

    /**
     * @param  array<int, string>  $platforms
     */
    private function buildDiscoverTrendsUserPrompt(Client $client, array $platforms): string
    {
        $lines = [
            'Discover exactly '.self::TRENDS_PER_REQUEST.' current trends relevant to this client across the given platforms.',
            'Only include trends that fit the client\'s industry, markets, audience, and content pillars; each summary must explain how this specific brand could use the trend in its own voice.',
            'Platforms: '.implode(', ', $platforms),
            'If you cannot browse the web, propose trend hypotheses and mark each with a confidence score (0.0-1.0).',
            '',
            'Respond with JSON: {"trends": [{"title": string, "summary": string, "platforms": string[], "confidence": number}]}',
        ];

        return implode("\n", $lines);
    }

    /**
     * Call DeepSeek's chat completions endpoint and parse/validate the structured JSON
     * response, retrying once (per Step 2.2: "Reject and retry up to 1 time if schema invalid")
     * when the response is missing, unparsable, or doesn't match the expected shape. Every
     * failure path logs *why* it failed (HTTP status + vendor error, empty/truncated content,
     * JSON error, or the exact schema violation) alongside finish_reason/token usage/latency and
     * a scrubbed, truncated excerpt of the model's response — never the API key or the prompt.
     *
     * @param  callable(array<string, mixed>): ?string  $validator  returns null when valid, else the reason
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>|null
     */
    private function requestStructured(string $method, string $systemPrompt, string $userPrompt, callable $validator, array $context, ?string $model = null, ?int $maxTokens = null): ?array
    {
        $model ??= (string) config('ai.deepseek.model', 'deepseek-chat');
        $context = [...$context, 'method' => $method, 'model' => $model];

        $maxTokens ??= (int) config('ai.deepseek.max_tokens', 1024);

        Log::info('DeepSeek request started', [
            ...$context,
            'max_tokens' => $maxTokens,
            'prompt_chars' => mb_strlen($systemPrompt) + mb_strlen($userPrompt),
        ]);

        $failure = null;

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $result = $this->callChatCompletions($systemPrompt, $userPrompt, [...$context, 'attempt' => $attempt, 'max_tokens' => $maxTokens], $model, $maxTokens);

            if ($result['payload'] !== null) {
                $failure = $validator($result['payload']);

                if ($failure === null) {
                    Log::info('DeepSeek request completed', [...$context, ...$result['meta'], 'attempt' => $attempt]);

                    return $result['payload'];
                }

                Log::warning('DeepSeek response failed schema validation', [
                    ...$context,
                    ...$result['meta'],
                    'attempt' => $attempt,
                    'reason' => $failure,
                    'top_level_keys' => array_keys($result['payload']),
                    'response_excerpt' => $this->excerpt((string) json_encode($result['payload'])),
                ]);
            } else {
                $failure = $result['failure'];
            }

            if ($attempt < self::MAX_ATTEMPTS) {
                // Re-sending a request that ran out of tokens with the same budget just fails the
                // same way (reasoning models spend it all thinking), so give the retry more room.
                $previousMaxTokens = $maxTokens;

                if (($result['meta']['finish_reason'] ?? null) === 'length') {
                    $maxTokens = min($maxTokens * 2, self::MAX_TOKENS_CEILING);
                }

                Log::warning('DeepSeek request retrying', [
                    ...$context,
                    'attempt' => $attempt,
                    'reason' => $failure,
                    'max_tokens' => $maxTokens,
                    'max_tokens_raised_from' => $maxTokens !== $previousMaxTokens ? $previousMaxTokens : null,
                ]);
            }
        }

        Log::error('DeepSeek request failed after retries', [...$context, 'attempts' => self::MAX_ATTEMPTS, 'last_reason' => $failure]);

        return null;
    }

    /**
     * One HTTP round trip. Returns the decoded JSON payload (or null) plus response metadata
     * (latency, finish_reason, token usage) for logging, and a short failure reason when the
     * payload couldn't be produced — each failure is also logged here with its own diagnostics.
     *
     * @param  array<string, mixed>  $context
     * @return array{payload: array<string, mixed>|null, failure: string|null, meta: array<string, mixed>}
     */
    private function callChatCompletions(string $systemPrompt, string $userPrompt, array $context, string $model, int $maxTokens): array
    {
        $startedAt = microtime(true);

        try {
            $response = $this->client()->post('/chat/completions', [
                'model' => $model,
                'max_tokens' => $maxTokens,
                'response_format' => ['type' => 'json_object'],
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
            ]);
        } catch (Throwable $e) {
            $meta = ['duration_ms' => $this->elapsedMs($startedAt)];

            // Http::retry() throws a RequestException once retries are exhausted on a 4xx/5xx.
            $status = $e instanceof RequestException ? $e->response->status() : null;
            $vendorError = $e instanceof RequestException ? $this->vendorError($e->response->json()) : null;

            Log::error('DeepSeek HTTP request threw an exception', [
                ...$context,
                ...$meta,
                'exception' => $e::class,
                'error' => $this->guardrail->scrub($e->getMessage()),
                'status' => $status,
                'vendor_error' => $vendorError,
            ]);

            return ['payload' => null, 'failure' => 'http exception: '.($vendorError ?? $e::class), 'meta' => $meta];
        }

        $meta = [
            'duration_ms' => $this->elapsedMs($startedAt),
            'status' => $response->status(),
            'finish_reason' => $response->json('choices.0.finish_reason'),
            'usage' => array_filter([
                'prompt_tokens' => $response->json('usage.prompt_tokens'),
                'completion_tokens' => $response->json('usage.completion_tokens'),
                'reasoning_tokens' => $response->json('usage.completion_tokens_details.reasoning_tokens'),
                'total_tokens' => $response->json('usage.total_tokens'),
            ], fn ($value) => $value !== null),
        ];

        if (! $response->successful()) {
            $vendorError = $this->vendorError($response->json());

            Log::warning('DeepSeek request rejected', [
                ...$context,
                ...$meta,
                'vendor_error' => $vendorError,
                'response_excerpt' => $vendorError === null ? $this->excerpt($response->body()) : null,
            ]);

            return ['payload' => null, 'failure' => "http {$response->status()}: ".($vendorError ?? 'no error message'), 'meta' => $meta];
        }

        $truncated = $meta['finish_reason'] === 'length';
        $content = $response->json('choices.0.message.content');

        if (! is_string($content) || trim($content) === '') {
            $reasoning = $response->json('choices.0.message.reasoning_content');

            Log::warning('DeepSeek returned empty content', [
                ...$context,
                ...$meta,
                'has_reasoning_content' => is_string($reasoning) && $reasoning !== '',
                'hint' => $truncated ? 'max_tokens was exhausted before any answer was written — raise DEEPSEEK_MAX_TOKENS' : null,
            ]);

            return ['payload' => null, 'failure' => $truncated ? 'empty content (truncated at max_tokens)' : 'empty content', 'meta' => $meta];
        }

        try {
            $decoded = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            Log::warning('DeepSeek returned unparsable JSON', [
                ...$context,
                ...$meta,
                'json_error' => $e->getMessage(),
                'content_chars' => mb_strlen($content),
                'response_excerpt' => $this->excerpt($content),
                'hint' => $truncated ? 'response was cut off at max_tokens — raise DEEPSEEK_MAX_TOKENS' : null,
            ]);

            return ['payload' => null, 'failure' => $truncated ? 'invalid JSON (truncated at max_tokens)' : 'invalid JSON: '.$e->getMessage(), 'meta' => $meta];
        }

        if (! is_array($decoded)) {
            Log::warning('DeepSeek returned JSON that is not an object', [
                ...$context,
                ...$meta,
                'response_excerpt' => $this->excerpt($content),
            ]);

            return ['payload' => null, 'failure' => 'JSON is not an object', 'meta' => $meta];
        }

        return ['payload' => $decoded, 'failure' => null, 'meta' => $meta];
    }

    private function client(): PendingRequest
    {
        $apiKey = (string) config('ai.deepseek.api_key', '');
        $timeout = (int) config('ai.deepseek.timeout', 30);
        $baseUrl = (string) config('ai.deepseek.base_url', 'https://api.deepseek.com');

        return Http::baseUrl($baseUrl)
            ->withToken($apiKey)
            ->acceptJson()
            ->timeout($timeout)
            // Trivial exponential backoff with jitter on 429/5xx, per Step 2.2.
            ->retry(3, fn (int $attempt): int => $this->backoffDelayMs($attempt), function (Throwable $exception) {
                if (! $exception instanceof RequestException) {
                    return true;
                }

                return $exception->response->status() === 429 || $exception->response->serverError();
            });
    }

    private function backoffDelayMs(int $attempt): int
    {
        $base = 200 * (2 ** ($attempt - 1));

        return $base + random_int(0, 100);
    }

    private function elapsedMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    /**
     * Pull the human-readable message out of a DeepSeek/OpenAI-style error body, if any.
     */
    private function vendorError(mixed $body): ?string
    {
        $message = is_array($body) ? ($body['error']['message'] ?? $body['message'] ?? null) : null;

        return is_string($message) ? $this->guardrail->scrub($message) : null;
    }

    /**
     * Scrubbed, length-capped slice of a model/vendor response for log context: enough to see
     * what came back (wrong key, prose instead of JSON, cut-off output) without dumping it all.
     */
    private function excerpt(string $text, int $limit = 500): string
    {
        $text = $this->guardrail->scrub($text);

        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        // Keep the head and the tail: the head shows the shape, the tail shows where it was cut off.
        return mb_substr($text, 0, $limit - 150).' … '.mb_substr($text, -150);
    }

    /**
     * Shared shape check for every method's response: `$key` must be an array of objects, each
     * carrying the given string fields. Returns null when valid, otherwise a precise reason
     * (e.g. `ideas[2].description must be a string, got null`) for the log.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $requiredStringFields
     */
    private function shapeError(array $data, string $key, array $requiredStringFields): ?string
    {
        if (! array_key_exists($key, $data)) {
            return "missing top-level \"{$key}\" key";
        }

        if (! is_array($data[$key])) {
            return "\"{$key}\" must be an array, got ".get_debug_type($data[$key]);
        }

        foreach ($data[$key] as $index => $item) {
            if (! is_array($item)) {
                return "{$key}[{$index}] must be an object, got ".get_debug_type($item);
            }

            foreach ($requiredStringFields as $field) {
                if (! isset($item[$field]) || ! is_string($item[$field])) {
                    return "{$key}[{$index}].{$field} must be a string, got ".get_debug_type($item[$field] ?? null);
                }
            }
        }

        return null;
    }
}
