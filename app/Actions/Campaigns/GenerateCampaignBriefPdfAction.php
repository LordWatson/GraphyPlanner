<?php

namespace App\Actions\Campaigns;

use App\Enums\ApprovalDecision;
use App\Enums\BriefStatus;
use App\Models\Campaign;
use App\Models\CampaignBriefRevision;
use App\Support\Campaigns\CampaignClock;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Step C-2.3 — the branded brief PDF (same "Studio Paper" language as the invoice and analytics
 * report, via `PdfBrand`). An approved brief prints the exact revision the client signed, with
 * the approval stamp ("Approved by … on …, revision 3") and its content hash; anything else
 * prints the working copy stamped as a draft. Streamed, never stored. Same document for staff and
 * the portal — a brief never carries billing or internal fields.
 */
class GenerateCampaignBriefPdfAction
{
    public function __invoke(Campaign $campaign): string
    {
        $brief = $campaign->brief;

        if ($brief === null || $brief->isEmpty()) {
            throw ValidationException::withMessages(['brief' => 'There’s no brief to download yet.']);
        }

        /** @var CampaignBriefRevision|null $approved */
        $approved = $brief->status === BriefStatus::Approved
            ? $brief->revisions()->where('decision', ApprovalDecision::Approved)->orderByDesc('number')->with('decider:id,name')->first()
            : null;

        File::ensureDirectoryExists(storage_path('fonts'));
        $started = microtime(true);
        $timezone = CampaignClock::timezone($campaign->organization);

        $pdf = Pdf::loadView('pdf.campaign-brief', [
            'campaign' => $campaign,
            'client' => $campaign->client,
            'organization' => $campaign->organization,
            'content' => $approved->content ?? $brief->content(),
            'approved' => $approved,
            'status' => $brief->status,
            'timezone' => $timezone,
            'generatedAt' => now()->setTimezone($timezone),
        ])->setPaper('a4')->output();

        Log::info('Campaign brief PDF generated', [
            'campaign_id' => $campaign->id,
            'org_id' => $campaign->org_id,
            'revision_id' => $approved?->id,
            'bytes' => strlen($pdf),
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
        ]);

        return $pdf;
    }
}
