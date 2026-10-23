<?php

namespace App\Actions\AI;

use App\Enums\AiRating;
use App\Models\AiFeedback;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Step M-7.1 (dev plan Step 2.9) — record one user's rating of one AI suggestion. Idempotent per
 * user/subject: a second rating (e.g. switching thumbs up to down) updates the same row rather than
 * stacking, thanks to the unique index. An implicit `Applied` signal is written the same way when a
 * suggestion is actually used, so acceptance rate can be tracked without an explicit click.
 */
class RecordAiFeedbackAction
{
    public function __invoke(Model $subject, ?User $user, AiRating $rating, ?string $reason = null): AiFeedback
    {
        /** @var int $orgId */
        $orgId = $subject->getAttribute('org_id');

        return DB::transaction(fn (): AiFeedback => AiFeedback::query()->updateOrCreate(
            [
                'user_id' => $user?->id,
                'subject_type' => $subject->getMorphClass(),
                'subject_id' => $subject->getKey(),
            ],
            [
                'org_id' => $orgId,
                'rating' => $rating,
                'reason' => $reason === null ? null : mb_substr(trim($reason), 0, 500),
            ],
        ));
    }
}
