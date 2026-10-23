<?php

namespace App\Models;

use App\Enums\AiTextJobKind;
use App\Enums\AiTextJobStatus;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Step M-7.2 — one async agency text AI task (captions/trends/ideas). The client supplies the uuid
 * so a retried dispatch is idempotent; the UI polls `status` and reads `result` once it settles.
 * `result` holds the exact payload the old synchronous endpoint returned.
 *
 * @property string $id
 * @property int $org_id
 * @property int|null $user_id
 * @property int|null $client_id
 * @property int|null $post_id
 * @property AiTextJobKind $kind
 * @property array<string, mixed>|null $params
 * @property AiTextJobStatus $status
 * @property array<string, mixed>|null $result
 * @property string|null $error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['id', 'org_id', 'user_id', 'client_id', 'post_id', 'kind', 'params', 'status', 'result', 'error'])]
class AiTextJob extends Model
{
    use BelongsToOrganization, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => AiTextJobKind::class,
            'status' => AiTextJobStatus::class,
            'params' => 'array',
            'result' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return BelongsTo<Post, $this>
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }
}
