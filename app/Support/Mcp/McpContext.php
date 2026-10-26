<?php

namespace App\Support\Mcp;

use App\Models\McpToken;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Container\Container;

/**
 * Step MCP-1.2 — the resolved identity of an authenticated MCP request: exactly one organization,
 * the token's capability scope set, and (optionally) the user the token is attributed to. Built by
 * `AuthenticateMcp` from the bearer token and bound into the container for the life of the request,
 * so every tool/resource reads tenancy + capability from here instead of guessing.
 *
 * It is the single source of "who is this and what may they do" — the MCP analogue of the signed-in
 * member the web UI's `OrganizationScope` reads. Scope *enforcement* (the `McpScope` enum + per-tool
 * gating) is MCP-1.3; this holds the raw scope strings so that step has something to check.
 */
readonly class McpContext
{
    /**
     * @param  list<string>  $scopes
     */
    public function __construct(
        public Organization $organization,
        public array $scopes,
        public ?User $user = null,
        public ?McpToken $token = null,
    ) {}

    /**
     * The context bound for the current request, or null on the unauthenticated local/stdio
     * transport (and in in-process tool tests) where no bearer token was resolved.
     */
    public static function current(): ?self
    {
        $container = Container::getInstance();

        return $container->bound(self::class) ? $container->make(self::class) : null;
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }

    /**
     * The user an MCP call acts as: the token's attributed user, or (when a token was issued without
     * one) the organization's owner. Driving the existing Actions/policies/`OrganizationScope` as a
     * real member is what lets MCP reuse the exact web-UI business logic (MCP-2); it also means a
     * token attributed to a non-Owner inherits that member's role-level permissions. Null only for a
     * misconfigured org with no Owner — the caller fails closed in that case.
     */
    public function actingUser(): ?User
    {
        return $this->user ?? $this->organization->owner;
    }
}
