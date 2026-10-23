<?php

namespace App\Actions\Posts;

use App\Models\Campaign;
use App\Models\Client;
use App\Models\SocialAccount;
use App\Support\Posts\BulkImportParseResult;
use App\Support\Posts\BulkPostRow;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Step M-5.8 (Phase 10) — parse a bulk-import CSV for one client into validated rows, collecting
 * per-row errors (unknown handle, bad date/time, unknown campaign code) without throwing, so the
 * UI can show a preview and the user can fix the file before anything is created. Account handles
 * and campaign codes are resolved against this client's own records only.
 *
 * CSV columns (header row, case-insensitive; order doesn't matter): `caption`, `hashtags`
 * (space- or comma-separated), `accounts` (`;`-separated handles), `date` (YYYY-MM-DD), `time`
 * (HH:MM), `format`, `first_comment`, `link`, `campaign` (a campaign code). Only `accounts` is
 * required per row.
 */
class ParseBulkPostImportAction
{
    /** The template header, also used for the downloadable template and the "known columns" check. */
    public const COLUMNS = ['caption', 'hashtags', 'accounts', 'date', 'time', 'format', 'first_comment', 'link', 'campaign'];

    public function __invoke(Client $client, string $csv): BulkImportParseResult
    {
        $handles = $client->socialAccounts()->get()
            ->mapWithKeys(fn (SocialAccount $account) => [mb_strtolower(trim($account->handle)) => $account->id]);
        $campaignCodes = $client->campaigns()->whereNotNull('code')->get()
            ->mapWithKeys(fn (Campaign $campaign) => [mb_strtolower(trim((string) $campaign->code)) => $campaign->id]);

        $stream = fopen('php://temp', 'r+');

        if ($stream === false) {
            return new BulkImportParseResult([], []);
        }

        fwrite($stream, $csv);
        rewind($stream);

        $header = fgetcsv($stream);

        if ($header === false) {
            fclose($stream);

            return new BulkImportParseResult([], []);
        }

        $header = array_map(fn ($column) => mb_strtolower(trim((string) $column)), $header);

        /** @var list<BulkPostRow> $rows */
        $rows = [];
        /** @var list<array{row: int, messages: list<string>}> $errors */
        $errors = [];
        $rowNumber = 1; // the header was row 1

        while (($record = fgetcsv($stream)) !== false) {
            $rowNumber++;

            // Skip a wholly blank line (a trailing newline in the file).
            if (implode('', array_map(fn ($v) => (string) $v, $record)) === '') {
                continue;
            }

            /** @var array<string, string> $data */
            $data = [];
            foreach ($header as $i => $column) {
                $data[$column] = trim((string) ($record[$i] ?? ''));
            }

            $messages = [];

            // Accounts (required) — split on ; or , and resolve each handle for this client.
            $accountIds = [];
            $rawAccounts = preg_split('/[;,]/', $data['accounts'] ?? '') ?: [];
            $rawAccounts = array_values(array_filter(array_map('trim', $rawAccounts), fn ($h) => $h !== ''));

            if ($rawAccounts === []) {
                $messages[] = (string) __('No accounts — list at least one handle in the "accounts" column.');
            }

            foreach ($rawAccounts as $handle) {
                $id = $handles->get(mb_strtolower($handle));
                if ($id === null) {
                    $messages[] = (string) __('Unknown account ":handle" for this client.', ['handle' => $handle]);
                } else {
                    $accountIds[] = $id;
                }
            }

            $date = $this->normalizeDate($data['date'] ?? '', $messages);
            $time = $this->normalizeTime($data['time'] ?? '', $messages);

            $campaignId = null;
            if (($data['campaign'] ?? '') !== '') {
                $campaignId = $campaignCodes->get(mb_strtolower($data['campaign']));
                if ($campaignId === null) {
                    $messages[] = (string) __('Unknown campaign code ":code".', ['code' => $data['campaign']]);
                }
            }

            if ($messages !== []) {
                $errors[] = ['row' => $rowNumber, 'messages' => $messages];

                continue;
            }

            $rows[] = new BulkPostRow(
                rowNumber: $rowNumber,
                caption: $data['caption'] ?? '',
                hashtags: $this->splitHashtags($data['hashtags'] ?? ''),
                accountIds: array_values(array_unique($accountIds)),
                campaignId: $campaignId,
                scheduledLocalDate: $date,
                scheduledLocalTime: $time,
                firstComment: ($data['first_comment'] ?? '') !== '' ? $data['first_comment'] : null,
                linkUrl: ($data['link'] ?? '') !== '' ? $data['link'] : null,
                format: ($data['format'] ?? '') !== '' ? mb_strtolower($data['format']) : null,
            );
        }

        fclose($stream);

        return new BulkImportParseResult($rows, $errors);
    }

    /**
     * @param  list<string>  $messages
     */
    private function normalizeDate(string $value, array &$messages): ?string
    {
        if ($value === '') {
            return null;
        }

        try {
            $date = Carbon::createFromFormat('!Y-m-d', $value);
        } catch (\Throwable) {
            $date = null;
        }

        if (! $date instanceof CarbonInterface || $date->format('Y-m-d') !== $value) {
            $messages[] = (string) __('Bad date ":value" — use YYYY-MM-DD.', ['value' => $value]);

            return null;
        }

        return $value;
    }

    /**
     * @param  list<string>  $messages
     */
    private function normalizeTime(string $value, array &$messages): ?string
    {
        if ($value === '') {
            return null;
        }

        if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) !== 1) {
            $messages[] = (string) __('Bad time ":value" — use HH:MM (24-hour).', ['value' => $value]);

            return null;
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    private function splitHashtags(string $value): array
    {
        if (trim($value) === '') {
            return [];
        }

        return array_values(array_filter(
            array_map(fn ($tag) => ltrim(trim($tag), '#'), preg_split('/[\s,]+/', $value) ?: []),
            fn ($tag) => $tag !== '',
        ));
    }
}
