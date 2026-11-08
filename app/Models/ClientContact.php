<?php

namespace App\Models;

use App\Enums\ClientContactRole;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Scopes\OrganizationScope;
use App\Support\WhatsApp\PhoneNumber;
use Database\Factories\ClientContactFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;
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
 * @property bool $receives_whatsapp
 * @property Carbon|null $whatsapp_consent_at
 * @property string|null $whatsapp_consent_source
 * @property int|null $whatsapp_consent_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable([
    'org_id', 'client_id', 'name', 'email', 'phone', 'role',
    'receives_review_emails', 'receives_invoices',
    'receives_whatsapp', 'whatsapp_consent_at', 'whatsapp_consent_source', 'whatsapp_consent_by',
])]
class ClientContact extends Model
{
    /** @use HasFactory<ClientContactFactory> */
    use BelongsToOrganization, HasFactory, Notifiable, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => ClientContactRole::class,
            'receives_review_emails' => 'boolean',
            'receives_invoices' => 'boolean',
            'receives_whatsapp' => 'boolean',
            'whatsapp_consent_at' => 'datetime',
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

    /**
     * Step W-1.2 — contacts who have consented to WhatsApp **and** have a number that can be
     * dialled. Both halves, because a consent with an unusable number is not a recipient.
     *
     * @param  Builder<ClientContact>  $query
     */
    public function scopeReceivingWhatsApp(Builder $query): void
    {
        $query->where('receives_whatsapp', true)->whereNotNull('phone');
    }

    /**
     * Step W-1.2 — where the `whatsapp` channel sends, or null if nowhere.
     *
     * Consent is the gate, and it is checked here rather than at each call site so no new caller
     * can forget it. `WhatsAppChannel` still checks the suppression list on top of this.
     */
    public function routeNotificationForWhatsApp(): ?string
    {
        return $this->receives_whatsapp ? PhoneNumber::e164($this->phone) : null;
    }

    /**
     * Record consent with its evidence: when, and how it was obtained. Clearing it keeps neither.
     */
    public function setWhatsAppConsent(bool $consented, string $source, ?int $byUserId = null): void
    {
        $this->forceFill([
            'receives_whatsapp' => $consented,
            'whatsapp_consent_at' => $consented ? now() : null,
            'whatsapp_consent_source' => $consented ? $source : null,
            'whatsapp_consent_by' => $consented ? $byUserId : null,
        ]);
    }
}
