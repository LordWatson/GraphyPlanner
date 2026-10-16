<?php

namespace App\Models;

use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string $default_timezone
 * @property string $default_currency
 * @property string|null $billing_details
 * @property string|null $payment_instructions
 * @property int $invoice_payment_terms_days
 * @property string|null $upload_post_key
 * @property string|null $upload_post_webhook_secret
 * @property string|null $xai_key
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'slug', 'default_timezone', 'default_currency', 'billing_details', 'payment_instructions', 'invoice_payment_terms_days', 'upload_post_key', 'upload_post_webhook_secret', 'xai_key'])]
#[Hidden(['upload_post_key', 'upload_post_webhook_secret', 'xai_key'])]
class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'upload_post_key' => 'encrypted',
            'upload_post_webhook_secret' => 'encrypted',
            'xai_key' => 'encrypted',
            'invoice_payment_terms_days' => 'integer',
        ];
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'org_id');
    }

    /**
     * @return HasMany<Client, $this>
     */
    public function clients(): HasMany
    {
        return $this->hasMany(Client::class, 'org_id');
    }

    /**
     * @return HasMany<StaffInvitation, $this>
     */
    public function staffInvitations(): HasMany
    {
        return $this->hasMany(StaffInvitation::class, 'org_id');
    }
}
