<?php

namespace App\Mcp\Prompts;

use App\Models\Client;
use App\Support\Mcp\McpTenant;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Prompts\Argument;

/**
 * Phase MCP-5.2 — a packaged "plan next week's content for {client}" workflow. A prompt is pure
 * orchestration: it returns a step-by-step instruction that drives the *existing* read + AI + draft
 * tools (get_brand_brain → get_calendar → discover_trends/recommend_posts → create_draft_post), with
 * no new business logic and a hard rule that output stays as drafts for human review — the assistant
 * never schedules or publishes from this prompt. Org-scoped like every other primitive.
 *
 * See .junie/plans/mcp-server.md.
 */
#[Name('plan_week')]
#[Title('Plan next week for a client')]
#[Description('Plans next week\'s content for a client: load the brand brain, find calendar gaps, pull trends and draft on-brand posts (left as drafts for review, never scheduled).')]
class PlanWeekPrompt extends Prompt
{
    public function handle(Request $request): Response
    {
        $org = McpTenant::organization();

        if ($org === null) {
            return Response::text('No organization is available for this connection.');
        }

        $client = Client::query()
            ->withoutOrgScope()
            ->where('org_id', $org->id)
            ->where('id', (int) $request->get('client_id'))
            ->first();

        if ($client === null) {
            return Response::text('No client with that id exists in this organization. Call list_clients first to find the right id.');
        }

        return Response::text(<<<PROMPT
        Plan next week's social content for the client "{$client->name}" (client_id {$client->id}) in Graphy.

        Work through these steps using the Graphy tools — do not invent data, read it:
        1. Call get_brand_brain(client_id={$client->id}) and ground every suggestion in that voice, audience and content pillars.
        2. Call get_calendar for the next 7 days to see what is already planned and where the gaps are.
        3. Call discover_trends(client_id={$client->id}) and recommend_posts(client_id={$client->id}) for on-brand ideas.
        4. For each idea that fills a real gap, call create_draft_post(client_id={$client->id}, ...) with a caption, hashtags and per-account targets at sensible local times.

        Rules:
        - Leave everything as DRAFTS. Never call schedule_post or transition a post past approval — a human reviews and schedules in Graphy.
        - Respect the brand brain's "do not say" guidance and keep captions within each platform's limits.
        - Summarise what you drafted (with the new post ids) at the end.
        PROMPT);
    }

    /**
     * @return array<int, Argument>
     */
    public function arguments(): array
    {
        return [
            new Argument('client_id', 'The id of the client to plan for (from list_clients).', required: true),
        ];
    }
}
