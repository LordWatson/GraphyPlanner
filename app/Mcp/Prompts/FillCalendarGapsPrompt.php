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
 * Phase MCP-5.2 — a packaged "fill the upcoming calendar gaps" workflow. Pure orchestration over the
 * existing get_calendar + recommend_posts + create_draft_post tools; output stays as drafts for a
 * human to review and schedule. Org-scoped like every other primitive.
 *
 * See .junie/plans/mcp-server.md.
 */
#[Name('fill_calendar_gaps')]
#[Title('Fill a client\'s calendar gaps')]
#[Description('Finds the upcoming days with nothing planned for a client and drafts on-brand filler posts (left as drafts for review, never scheduled).')]
class FillCalendarGapsPrompt extends Prompt
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
        Fill the upcoming content gaps for the client "{$client->name}" (client_id {$client->id}) in Graphy.

        1. Call get_calendar(client_id={$client->id}) for the next 14 days and list the days that have NO scheduled or draft posts — those are the gaps.
        2. Call get_brand_brain(client_id={$client->id}) so every filler matches the client's voice and pillars.
        3. For each gap day, call recommend_posts(client_id={$client->id}) for ideas and then create_draft_post(client_id={$client->id}, ...) with a target at a sensible local time on that day.

        Rules:
        - One draft per gap day is plenty; do not over-post or duplicate existing content.
        - Leave everything as DRAFTS — never schedule or publish. A human reviews and schedules in Graphy.
        - Report the gap days you found and the draft ids you created.
        PROMPT);
    }

    /**
     * @return array<int, Argument>
     */
    public function arguments(): array
    {
        return [
            new Argument('client_id', 'The id of the client whose calendar to fill (from list_clients).', required: true),
        ];
    }
}
