<?php

namespace App\Jobs;

use App\Actions\ReviewTokens\CreateReviewTokenAction;
use App\Enums\PostStatus;
use App\Enums\Role;
use App\Mail\ClientApprovalReminderMail;
use App\Models\Organization;
use App\Models\Post;
use App\Models\User;
use App\Notifications\ApprovalOverdue;
use App\Services\PostApprovalSlaService;
use App\Support\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

/**
 * Step M-6.5 (Phase 9) — once a day, for every post that has sat past its org's approval SLA, nudge
 * the right people: an in-app reminder to the studio (Owner/Strategist), and — for a post waiting on
 * the client — a reminder email to the client's review-email contacts with a fresh review link.
 * Throttled to once per 24h per post via `posts.last_reminded_at`, so a daily run never double-sends.
 */
class SendOverdueApprovalRemindersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
        $this->onQueue(Queues::NOTIFICATIONS);
    }

    public function handle(PostApprovalSlaService $sla, CreateReviewTokenAction $createReviewToken): void
    {
        $cutoff = now()->subDay();
        $reminded = 0;

        Organization::query()
            ->whereNotNull('approval_sla_hours')
            ->where('approval_sla_hours', '>', 0)
            ->each(function (Organization $org) use ($sla, $createReviewToken, $cutoff, &$reminded) {
                $posts = $sla->overdueQuery($org)
                    ->where(fn ($query) => $query->whereNull('last_reminded_at')->orWhere('last_reminded_at', '<=', $cutoff))
                    ->with('client')
                    ->get();

                if ($posts->isEmpty()) {
                    return;
                }

                $studio = User::query()
                    ->where('org_id', $org->id)
                    ->whereIn('role', [Role::Owner, Role::Strategist])
                    ->get();

                foreach ($posts as $post) {
                    foreach ($studio as $member) {
                        $member->notify(new ApprovalOverdue($post));
                    }

                    if ($post->status === PostStatus::WaitingClient) {
                        $this->emailClient($post, $createReviewToken);
                    }

                    $post->forceFill(['last_reminded_at' => now()])->save();
                    $reminded++;
                }
            });

        Log::info('Overdue approval reminders sent', ['post_count' => $reminded]);
    }

    private function emailClient(Post $post, CreateReviewTokenAction $createReviewToken): void
    {
        $recipients = $post->client->reviewEmailRecipients();

        if ($recipients === []) {
            return;
        }

        ['token' => $plainToken] = $createReviewToken($post->client, $post);
        $reviewUrl = URL::to("/review/{$plainToken}");

        foreach ($recipients as $recipient) {
            Mail::to($recipient['email'])->queue(new ClientApprovalReminderMail($post, $reviewUrl));
        }
    }
}
