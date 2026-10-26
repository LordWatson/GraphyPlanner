<?php

namespace Tests\Fixtures\Mcp;

use App\Mcp\Tools\ListClientsTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use Laravel\Mcp\Server\Tool;

/**
 * A minimal MCP server used only by the MCP-1.3 scope-gating tests. It registers the read-only
 * `list_clients` tool and the test-only `sample_write` tool so the in-process test harness can
 * dispatch each by name and assert the capability gate (scope present vs. absent) on both.
 */
#[Name('Scope Test')]
#[Version('0.0.0')]
class ScopeTestServer extends Server
{
    /**
     * @var array<int, class-string<Tool>>
     */
    protected array $tools = [
        ListClientsTool::class,
        SampleWriteTool::class,
    ];
}
