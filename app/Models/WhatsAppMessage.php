<?php

namespace App\Models;

use App\Enums\WhatsAppMessageStatus;
use App\Models\Concerns\BelongsToOrganization;
use App\Support\WhatsApp\PhoneNumber;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Step W-3.1 — one WhatsApp message: what was sent, to whom, and what became of it.
 *
 * Written by `WhatsAppChannel` the moment a send is attempted (including the attempts that never
 * leave the building — `Skipped`, with the reason), and updated by Twilio's status callback.
 *
 * `to_phone` is `Hidden`, so the full number cannot reach an Inertia prop or a JSON response by
 * accident; `masked_phone` is what any view shows.
 *
 * @property int $id
 * @property int $org_id
 * @property int|null $user_id
 * @property int|null $client_contact_id
 * @property string $to_phone
 * @property string|null $notification_type
 * @property string $template
 * @property string|null $provider_message_id
 * @property WhatsAppMessageStatus $status
 * @property string|null $error_code
 * @property string|null $error_reason
 * @property Carbon|null $delivered_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'org_id', 'user_id', 'client_contact_id', 'to_phone', 'notification_type', 'template',
    'provider_message_id', 'status', 'error_code', 'error_reason', 'delivered_at',
])]
#[Hidden(['to_phone'])]
class WhatsAppMessage extends Model
{
    use BelongsToOrganization;

    // Eloquent would guess `whats_app_messages`.
    protected $table = 'whatsapp_messages';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => WhatsAppMessageStatus::class,
            'delivered_at' => 'datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<ClientContact, $this>
     */
    public function clientContact(): BelongsTo
    {
        return $this->belongsTo(ClientContact::class);
    }

    /**
     * The only form of the number that may be shown or logged.
     */
    public function maskedPhone(): string
    {
        return PhoneNumber::mask($this->to_phone) ?? 'unknown';
    }

    /**
     * @param  Builder<WhatsAppMessage>  $query
     */
    public function scopeNewestFirst(Builder $query): void
    {
        $query->orderByDesc('created_at')->orderByDesc('id');
    }
}
