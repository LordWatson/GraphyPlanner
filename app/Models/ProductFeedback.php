<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\ProductFeedbackFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Step M-8.2 — a single piece of in-app product feedback from a partner org's staff member. Scoped
 * to the sender's organization (the `OrganizationScope` safety net keeps one org's notes out of
 * another's queries; super admins are org-less and see them all).
 *
 * @property int $id
 * @property int $org_id
 * @property int|null $user_id
 * @property string|null $page
 * @property string $message
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['org_id', 'user_id', 'page', 'message'])]
class ProductFeedback extends Model
{
    /** @use HasFactory<ProductFeedbackFactory> */
    use BelongsToOrganization, HasFactory;

    protected $table = 'product_feedback';

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    /**
     * The staff member who sent it. Null once their account is removed (the note is kept).
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
