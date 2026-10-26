<?php

namespace Tests\Fixtures\Mcp;

use App\Enums\McpScope;
use App\Mcp\Tools\McpTool;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

/**
 * A stand-in "write" tool used only by the MCP-1.3 scope-gating tests. It requires the elevated
 * `WriteDrafts` scope, so a read-only token must be rejected before `handleAuthorized()` runs. It
 * exists so the gate can be proven against a tool that needs a scope a default token lacks, without
 * shipping a real write tool before Phase MCP-3.
 */
#[Name('sample_write')]
#[Description('Test-only write tool that requires the write:drafts scope.')]
#[IsDestructive]
class SampleWriteTool extends McpTool
{
    /**
     * @return list<McpScope>
     */
    protected function requiredScopes(): array
    {
        return [McpScope::WriteDrafts];
    }

    protected function handleAuthorized(Request $request): Response|ResponseFactory
    {
        return Response::text('wrote a draft');
    }
}
