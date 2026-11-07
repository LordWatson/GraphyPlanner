<?php

namespace App\Http\Controllers\Settings;

use App\Contracts\AssetStorage;
use App\Enums\DataExportStatus;
use App\Http\Controllers\Controller;
use App\Jobs\BuildOrganizationDataExportJob;
use App\Models\DataExport;
use App\Services\Security\OrganizationDataExport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Settings → Data export (Owner only). "Download everything you hold for us" as a product feature
 * rather than a support ticket — the portability half of GDPR, next to the delete half that
 * `PurgeOrganizationJob` already covers.
 *
 * Thin: the archive is built by {@see BuildOrganizationDataExportJob} on the reports queue and the
 * set list lives in {@see OrganizationDataExport}.
 */
class DataExportController extends Controller
{
    /** Exports listed on the page. Older rows stay in the table as receipts. */
    private const RECENT = 10;

    public function index(Request $request): Response
    {
        $organization = $request->user()->organization;

        $this->authorize('update', $organization);

        return Inertia::render('settings/data-export', [
            'exports' => DataExport::query()
                ->with('requester:id,name')
                ->latest()
                ->limit(self::RECENT)
                ->get()
                ->map(fn (DataExport $export) => [
                    'id' => $export->id,
                    'status' => $export->status->value,
                    'status_label' => $export->status->label(),
                    'requested_by' => $export->requester?->name,
                    'requested_at' => $export->created_at?->toDayDateTimeString(),
                    'rows' => $export->row_count,
                    'size' => $export->size_bytes === null ? null : self::humanSize($export->size_bytes),
                    'expires_at' => $export->expires_at?->toDayDateTimeString(),
                    'failure_reason' => $export->failure_reason,
                    'downloadable' => $export->isDownloadable(),
                ])
                ->all(),
            'retentionDays' => (int) config('exports.retention_days', 7),
            // Only one at a time: the archive is the whole organisation, and a second concurrent
            // build is only ever a doubled bill.
            'pending' => DataExport::query()->pending()->exists(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $organization = $request->user()->organization;

        $this->authorize('update', $organization);

        $export = DB::transaction(function () use ($request, $organization): ?DataExport {
            if (DataExport::query()->pending()->lockForUpdate()->exists()) {
                return null;
            }

            return DataExport::create([
                'org_id' => $organization->id,
                'requested_by' => $request->user()->id,
                'status' => DataExportStatus::Pending,
            ]);
        });

        if ($export === null) {
            $this->toast('An export is already being prepared. You will be told when it is ready.', 'info');

            return back();
        }

        // After the commit, or the worker can pick the job up before the row it needs exists.
        DB::afterCommit(fn () => BuildOrganizationDataExportJob::dispatch($export->id));

        Log::info('Data export requested', [
            'data_export_id' => $export->id,
            'org_id' => $organization->id,
            'user_id' => $request->user()->id,
        ]);

        $this->toast('Preparing your export. We will tell you when it is ready.');

        return back();
    }

    /**
     * Stream the archive. The bytes go through this route rather than a storage URL, so the private
     * disk is never handed out and the expiry is re-checked on every hit — a link in somebody's
     * downloads list must stop working the moment the retention window closes.
     */
    public function download(Request $request, DataExport $dataExport, AssetStorage $storage): StreamedResponse
    {
        $organization = $request->user()->organization;

        $this->authorize('update', $organization);
        // Route-model binding resolves without the org scope so the policy can answer, which is
        // what makes this check the one that matters.
        abort_unless($dataExport->org_id === $request->user()->org_id, 403);
        abort_unless($dataExport->isDownloadable(), 410, __('This export is no longer available.'));

        $stream = $storage->readStream((string) $dataExport->disk, (string) $dataExport->path);

        abort_if($stream === null, 404);

        Log::info('Data export downloaded', [
            'data_export_id' => $dataExport->id,
            'org_id' => $dataExport->org_id,
            'user_id' => $request->user()->id,
        ]);

        return response()->stream(function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        }, 200, [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => 'attachment; filename="alima-export-'.$dataExport->id.'.zip"',
            // Never let a proxy or a shared browser keep a copy of an organisation's whole record.
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }

    private static function humanSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $size = (float) $bytes;
        $unit = 0;

        while ($size >= 1024 && $unit < count($units) - 1) {
            $size /= 1024;
            $unit++;
        }

        return round($size, $unit === 0 ? 0 : 1).' '.$units[$unit];
    }

    private function toast(string $message, string $type = 'success'): void
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);
    }
}
