<?php

namespace App\Mcp\Tools;

use App\Actions\Posts\BuildCalendarOccurrencesAction;
use App\Actions\Posts\GetCalendarPostsAction;
use App\Enums\McpScope;
use App\Support\Calendar\CalendarWindow;
use App\Support\Mcp\McpTenant;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Phase MCP-2.3 — the content calendar for a window, reusing the exact projection the web calendar
 * uses: `GetCalendarPostsAction` fetches the window's posts (org-scoped by the acting member) and
 * `BuildCalendarOccurrencesAction` renders one occurrence per target with BOTH the account-local and
 * the org-timezone date/time, so the same London/Dubai post reports both correctly through MCP.
 * Read-only; requires `Read`.
 *
 * See .junie/plans/mcp-server.md.
 */
#[Name('get_calendar')]
#[Title('Get content calendar')]
#[Description('Returns scheduled post occurrences in a date window, each rendered in both the account\'s local timezone and the org timezone. Optional from/to (YYYY-MM-DD, defaults to the current month) and client_id/campaign_id/status/platform filters. Read-only.')]
#[IsReadOnly]
class GetCalendarTool extends McpTool
{
    public function __construct(
        private readonly GetCalendarPostsAction $getPosts,
        private readonly BuildCalendarOccurrencesAction $buildOccurrences,
    ) {}

    /**
     * @return list<McpScope>
     */
    protected function requiredScopes(): array
    {
        return [McpScope::Read];
    }

    protected function handleAuthorized(Request $request): Response|ResponseFactory
    {
        $org = McpTenant::organization();

        if ($org === null) {
            return Response::error('No organization is available for this connection.');
        }

        $user = $this->actAsOrganizationMember();

        if ($user === null) {
            return Response::error('No member is available to authorize this connection.');
        }

        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'client_id' => ['nullable', 'integer'],
            'campaign_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'string', 'max:40'],
            'platform' => ['nullable', 'string', 'max:40'],
        ]);

        $orgTimezone = $org->default_timezone ?: 'UTC';
        $window = CalendarWindow::resolve($validated['from'] ?? null, $validated['to'] ?? null, $orgTimezone);

        $filters = array_filter([
            'client_id' => $validated['client_id'] ?? null,
            'campaign_id' => $validated['campaign_id'] ?? null,
            'status' => $validated['status'] ?? null,
            'platform' => $validated['platform'] ?? null,
        ], fn ($value) => $value !== null);

        $posts = ($this->getPosts)($user, $filters, $window);
        $occurrences = ($this->buildOccurrences)($posts, $orgTimezone);

        return Response::structured([
            'organization' => $org->name,
            'window' => $window->toArray(),
            'org_timezone' => $orgTimezone,
            'count' => count($occurrences),
            'occurrences' => $occurrences,
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'from' => $schema->string()->format('date')->description('Window start (YYYY-MM-DD). Defaults to the current month grid.'),
            'to' => $schema->string()->format('date')->description('Window end (YYYY-MM-DD). Capped to a ~2 month span.'),
            'client_id' => $schema->integer()->description('Only this client\'s posts.'),
            'campaign_id' => $schema->integer()->description('Only this campaign\'s posts.'),
            'status' => $schema->string()->description('Only posts in this status.'),
            'platform' => $schema->string()->description('Only targets on this platform.'),
        ];
    }
}
