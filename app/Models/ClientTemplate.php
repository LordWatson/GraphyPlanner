<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Support\Clients\ClientTemplateSnapshot;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A reusable set of client settings — "how we set up a local service business" — saved once and
 * applied to every new client of that kind.
 *
 * The settings live in one JSON `snapshot` rather than mirrored columns; see
 * {@see ClientTemplateSnapshot} for what is copied and, more importantly, what is deliberately
 * never copied.
 *
 * @property int $id
 * @property int $org_id
 * @property int|null $created_by
 * @property string $name
 * @property string|null $description
 * @property array{client?: array<string, mixed>, brand_brain?: array<string, mixed>} $snapshot
 * @property bool $is_built_in
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['org_id', 'created_by', 'name', 'description', 'snapshot', 'is_built_in'])]
class ClientTemplate extends Model
{
    use BelongsToOrganization;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
            'is_built_in' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * A short, human list of what this template will set, for the confirmation step.
     *
     * Shown because applying a template changes a dozen settings at once and somebody should be
     * able to see which before they agree to it — a template that silently sets a retainer is a
     * template people stop trusting.
     *
     * @return list<string>
     */
    public function summary(): array
    {
        $client = (array) ($this->snapshot['client'] ?? []);
        $brain = (array) ($this->snapshot['brand_brain'] ?? []);

        $parts = [];

        if (isset($client['default_approval_mode'])) {
            $parts[] = 'Approval: '.str_replace('_', ' ', (string) $client['default_approval_mode']);
        }

        if (isset($client['target_posts_per_week'])) {
            $parts[] = $client['target_posts_per_week'].' posts a week';
        }

        if (isset($client['billing_cycle'])) {
            $parts[] = 'Billing: '.$client['billing_cycle'];
        }

        if (isset($client['report_schedule'])) {
            $parts[] = 'Reports: '.$client['report_schedule'];
        }

        if ($brain !== []) {
            $parts[] = 'Brand brain: '.count($brain).' field(s)';
        }

        return $parts;
    }
}
