<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Step MCP-1.5 — one audit row per executed MCP tool call (success or failure). Written by
 * `RecordMcpCallAction`, read back by the MCP-7.1 usage/audit UI. It stores the resolving org, the
 * token, the tool name, the argument *keys* (never values — a value could be a secret), the outcome
 * and the duration. There is no column that can carry a secret, token or argument value.
 *
 * `BelongsToOrganization` for parity with the other org-owned logs (`AiUsageLog`); on the MCP
 * transport there is no signed-in web member, so `OrganizationScope` is a no-op and the explicit
 * `org_id` on write is what scopes it. The audit UI reads with an explicit org filter.
 *
 * @property int $id
 * @property int $org_id
 * @property int|null $mcp_token_id
 * @property string $tool
 * @property list<string> $argument_keys
 * @property string $outcome
 * @property int|null $duration_ms
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['org_id', 'mcp_token_id', 'tool', 'argument_keys', 'outcome', 'duration_ms'])]
class McpCallLog extends Model
{
    use BelongsToOrganization;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'argument_keys' => 'array',
            'duration_ms' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    /**
     * @return BelongsTo<McpToken, $this>
     */
    public function token(): BelongsTo
    {
        return $this->belongsTo(McpToken::class, 'mcp_token_id');
    }
}
