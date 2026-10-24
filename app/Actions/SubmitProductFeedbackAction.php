<?php

namespace App\Actions;

use App\Enums\Role;
use App\Models\ProductFeedback;
use App\Models\User;
use App\Notifications\ProductFeedbackReceived;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Step M-8.2 — stores one piece of in-app product feedback and alerts the platform team.
 *
 * The write and the super-admin notifications are one transaction so a feedback row never lands
 * without its alert queued (notifications are `ShouldQueue` + `after_commit`, so nothing is sent for
 * a rolled-back write). The feedback body is user-entered product commentary, never a secret, but we
 * log only its length so the log stays lean and carries no free text.
 */
class SubmitProductFeedbackAction
{
    public function __invoke(User $user, string $message, ?string $page): ProductFeedback
    {
        $message = trim($message);
        $page = $page !== null ? mb_substr(trim($page), 0, 255) : null;

        return DB::transaction(function () use ($user, $message, $page): ProductFeedback {
            $feedback = ProductFeedback::create([
                'org_id' => $user->org_id,
                'user_id' => $user->id,
                'page' => $page,
                'message' => $message,
            ]);

            $superAdmins = User::query()->where('role', Role::SuperAdmin)->get();

            Notification::send($superAdmins, new ProductFeedbackReceived($feedback));

            Log::info('Product feedback received', [
                'product_feedback_id' => $feedback->id,
                'org_id' => $user->org_id,
                'user_id' => $user->id,
                'page' => $page,
                'message_length' => mb_strlen($message),
                'notified_super_admins' => $superAdmins->count(),
            ]);

            return $feedback;
        });
    }
}
