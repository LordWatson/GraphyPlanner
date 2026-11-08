<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One `Idempotency-Key` an API client has used, and the answer it got.
 *
 * Reserved before the write runs and completed after, so the row's own state says which of the
 * three things is true: nothing has been tried, something is in flight, or there is an answer to
 * hand back. See `App\Services\Api\IdempotencyStore`.
 *
 * @property int $id
 * @property int $org_id
 * @property string $key
 * @property string $endpoint
 * @property string $request_hash
 * @property int|null $response_status
 * @property array<string, mixed>|null $response_body
 * @property Carbon $created_at
 */
#[Fillable(['org_id', 'key', 'endpoint', 'request_hash', 'response_status', 'response_body'])]
class ApiIdempotencyKey extends Model
{
    use BelongsToOrganization;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'response_body' => 'array',
            'response_status' => 'integer',
        ];
    }

    /**
     * A reserved row whose request never finished — a crash mid-write, or a request still running.
     */
    public function isInFlight(): bool
    {
        return $this->response_status === null;
    }
}
