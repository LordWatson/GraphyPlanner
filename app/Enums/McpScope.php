<?php

namespace App\Enums;

/**
 * Step MCP-1.3 — the capability scopes an MCP token can carry. A scope is the *permission to even
 * attempt* a class of tool: read-only by default, with elevated scopes the Owner opts into for
 * writing, scheduling/publishing and AI. The scope gate is checked before any Action runs, but it
 * is only *necessary*, never *sufficient* — the underlying Action/policy still applies (so a
 * `WriteSchedule` token still can't touch another org). See .junie/plans/mcp-server.md §MCP-1.3.
 *
 * The backing string is the value persisted in `mcp_tokens.scopes` and compared in
 * `McpContext::hasScope()`, so these strings are a stable wire format — do not rename casually.
 */
enum McpScope: string
{
    case Read = 'read';
    case WriteDrafts = 'write:drafts';
    case WriteSchedule = 'write:schedule';
    case Ai = 'ai';
    case Inbox = 'inbox';

    /**
     * The scope set a brand-new token gets unless the Owner opts into more: read-only. Elevated
     * scopes (write/schedule/ai/inbox) are always an explicit choice. Single source of truth for
     * the default, reused by `CreateMcpTokenAction` and the MCP-6.1 settings page.
     *
     * @return list<self>
     */
    public static function default(): array
    {
        return [self::Read];
    }

    /**
     * The default scope set as the plain strings stored on the token.
     *
     * @return list<string>
     */
    public static function defaultValues(): array
    {
        return array_map(fn (self $scope): string => $scope->value, self::default());
    }

    public function label(): string
    {
        return match ($this) {
            self::Read => 'Read agency data',
            self::WriteDrafts => 'Create & edit drafts',
            self::WriteSchedule => 'Schedule & publish',
            self::Ai => 'Use AI features',
            self::Inbox => 'Read & draft inbox replies',
        };
    }
}
