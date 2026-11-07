<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\AuditLogRequest;
use App\Models\User;
use App\Services\Security\AuditTrail;
use Carbon\CarbonImmutable;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Settings → Audit log (Owner only). Who did what, when — across staff, posts, campaigns and plan
 * changes — and a CSV of the same window, which is what a security questionnaire or a client's
 * procurement team actually asks for.
 */
class AuditLogController extends Controller
{
    /** Rows rendered on the page. The export carries the whole window. */
    private const ON_SCREEN = 200;

    public function index(AuditLogRequest $request, AuditTrail $trail): Response
    {
        [$from, $to] = $request->window();
        $organization = $request->user()->organization;
        $entries = $trail->forOrganization($organization, $from, $to, $request->actorId());
        $timezone = (string) $organization->default_timezone;

        return Inertia::render('settings/audit-log', [
            'entries' => $entries->take(self::ON_SCREEN)->map(fn (array $entry) => [
                ...$entry,
                // Shown in the organization's timezone; the CSV carries the same.
                'at' => $this->localise($entry['at'], $timezone),
            ])->all(),
            'total' => $entries->count(),
            'onScreen' => self::ON_SCREEN,
            'timezone' => $timezone,
            'filters' => [
                'from' => $from->setTimezone($timezone)->toDateString(),
                'to' => $to->setTimezone($timezone)->toDateString(),
                'actor' => $request->actorId(),
            ],
            'members' => User::query()
                ->where('org_id', $organization->id)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (User $user) => ['value' => (string) $user->id, 'label' => $user->name])
                ->all(),
        ]);
    }

    public function export(AuditLogRequest $request, AuditTrail $trail): StreamedResponse
    {
        [$from, $to] = $request->window();
        $organization = $request->user()->organization;
        $entries = $trail->forOrganization($organization, $from, $to, $request->actorId());
        $timezone = (string) $organization->default_timezone;

        return response()->streamDownload(function () use ($entries, $timezone) {
            $handle = fopen('php://output', 'w');

            if ($handle === false) {
                throw new RuntimeException('Unable to open the output stream for the audit log export.');
            }

            fputcsv($handle, ['When ('.$timezone.')', 'Area', 'Action', 'Who', 'What', 'Detail']);

            foreach ($entries as $entry) {
                fputcsv($handle, [
                    $this->localise($entry['at'], $timezone),
                    $entry['source'],
                    $entry['action'],
                    $entry['actor'],
                    $entry['subject'],
                    $entry['detail'],
                ]);
            }

            fclose($handle);
        }, 'audit-log-'.$from->toDateString().'-to-'.$to->toDateString().'.csv', ['Content-Type' => 'text/csv']);
    }

    /**
     * Stored timestamps are UTC strings straight from the driver; an auditor reads them in the
     * organization's own working hours.
     */
    private function localise(string $at, string $timezone): string
    {
        return CarbonImmutable::parse($at, 'UTC')->setTimezone($timezone)->format('Y-m-d H:i');
    }
}
