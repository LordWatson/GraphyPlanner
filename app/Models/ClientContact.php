<?php

namespace App\Models;

use App\Enums\ClientContactRole;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Scopes\OrganizationScope;
use Database\Factories\ClientContactFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Step M-6.1 — a named person at a client (approver, billing, marketing lead or other) with
 * per-channel email preferences. Replaces the single `clients.approval_email` / `billing_email`
 * so review emails reach every approver and invoices reach every billing contact. Contacts never
 * count as staff seats (Step M-10.1).
 *
 * @property int $id
 * @property int $org_id
 * @property int $client_id
 * @property string|null $name
 * @property string $email
 * @property string|null $phone
 * @property ClientContactRole $role
 * @property bool $receives_review_emails
 * @property bool $receives_invoices
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable([
    'org_id', 'client_id', 'name', 'email', 'phone', 'role',
    'receives_review_emails', 'receives_invoices',
])]
class ClientContact extends Model
{
    /** @use HasFactory<ClientContactFactory> */
    use BelongsToOrganization, HasFactory, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => ClientContactRole::class,
            'receives_review_emails' => 'boolean',
            'receives_invoices' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed()->withoutGlobalScope(OrganizationScope::class);
    }

    /**
     * @param  Builder<ClientContact>  $query
     */
    public function scopeReceivingReviewEmails(Builder $query): void
    {
        $query->where('receives_review_emails', true);
    }

    /**
     * @param  Builder<ClientContact>  $query
     */
    public function scopeReceivingInvoices(Builder $query): void
    {
        $query->where('receives_invoices', true);
    }
}
