<?php

namespace App\Models;

use App\Enums\RegistrationInterestStatus;
use Database\Factories\RegistrationInterestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Someone who filled in the public "register interest" form while signup is closed and the first
 * clients are onboarded by hand. Platform-level (no org), worked by super admins only.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string|null $phone
 * @property string $company_name
 * @property string|null $location
 * @property RegistrationInterestStatus $status
 * @property Carbon|null $demo_at
 * @property string|null $notes
 * @property Carbon|null $contacted_at
 * @property string|null $ip_address
 * @property string|null $country
 * @property string|null $city
 * @property string|null $timezone
 * @property string|null $locale
 * @property string|null $user_agent
 * @property string|null $referrer
 * @property string|null $utm_source
 * @property string|null $utm_medium
 * @property string|null $utm_campaign
 * @property string|null $referral_code
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'name', 'email', 'phone', 'company_name', 'location', 'status', 'demo_at', 'notes', 'contacted_at',
    'ip_address', 'country', 'city', 'timezone', 'locale', 'user_agent', 'referrer',
    'utm_source', 'utm_medium', 'utm_campaign', 'referral_code',
])]
class RegistrationInterest extends Model
{
    /** @use HasFactory<RegistrationInterestFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => RegistrationInterestStatus::class,
            'demo_at' => 'datetime',
            'contacted_at' => 'datetime',
        ];
    }
}
