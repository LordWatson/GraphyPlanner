<?php

namespace App\Actions\Posts\Approvals;

use App\Enums\PostStatus;
use App\Models\ApprovalStep;
use App\Models\Post;
use App\Support\Campaigns\CampaignClock;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Step M-6.3 — the approval certificate: a branded PDF record of who approved a post, when, under
 * which policy, and the content hash of the exact revision they approved. For regulated clients
 * (finance, health) this is the auditable proof of sign-off. Only an approved post has one.
 */
class GenerateApprovalCertificatePdfAction
{
    public function __invoke(Post $post): string
    {
        if ($post->status !== PostStatus::Approved) {
            throw ValidationException::withMessages([
                'post' => 'An approval certificate is only available once the post is approved.',
            ]);
        }

        $post->loadMissing(['client', 'organization', 'approvalSteps.contact', 'approvalSteps.decidedBy']);

        $revision = $post->revisions()->whereNotNull('shared_at')->latest('id')->first();
        $timezone = CampaignClock::timezone($post->organization);

        $decisions = $post->approvalSteps
            ->filter(fn (ApprovalStep $step) => $step->decided_at !== null)
            ->sortBy('position')
            ->map(function (ApprovalStep $step) use ($timezone) {
                // A contact-backed step names its approver; the generic (contactless) step is the
                // client signing off as a whole (an anonymous review-link or single-approver decision).
                $approver = $step->client_contact_id !== null
                    ? ($step->contact->name ?? $step->contact->email ?? 'An approver')
                    : 'The client';

                return [
                    'approver' => $approver,
                    'email' => $step->client_contact_id !== null ? $step->contact->email : null,
                    'decision' => $step->decision->label(),
                    'decided_at' => $step->decided_at?->setTimezone($timezone)->format('j M Y, H:i'),
                ];
            })
            ->values();

        File::ensureDirectoryExists(storage_path('fonts'));
        $started = microtime(true);

        $pdf = Pdf::loadView('pdf.approval-certificate', [
            'post' => $post,
            'client' => $post->client,
            'organization' => $post->organization,
            'policy' => $post->client->approval_policy,
            'decisions' => $decisions,
            'revision' => $revision,
            'contentHash' => $revision?->contentHash(),
            'timezone' => $timezone,
            'generatedAt' => now()->setTimezone($timezone),
        ])->setPaper('a4')->output();

        Log::info('Approval certificate PDF generated', [
            'post_id' => $post->id,
            'org_id' => $post->org_id,
            'revision_id' => $revision?->id,
            'bytes' => strlen($pdf),
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
        ]);

        return $pdf;
    }
}
