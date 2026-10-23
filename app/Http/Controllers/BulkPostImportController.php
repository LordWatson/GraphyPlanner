<?php

namespace App\Http\Controllers;

use App\Actions\Posts\CreatePostsInBulkAction;
use App\Actions\Posts\ParseBulkPostImportAction;
use App\Models\Client;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Support\Posts\BulkPostRow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Step M-5.8 (Phase 10) — bulk CSV import of posts for a client: upload, preview (with per-row
 * errors), then create a whole batch of drafts in one transaction. Thin controller: parsing and
 * creation live in `ParseBulkPostImportAction` / `CreatePostsInBulkAction`.
 */
class BulkPostImportController extends Controller
{
    public function index(Client $client): Response
    {
        $this->authorize('create', [Post::class, $client]);

        return Inertia::render('posts/bulk-import', [
            'client' => ['id' => $client->id, 'name' => $client->name],
            'columns' => ParseBulkPostImportAction::COLUMNS,
            // The handles and campaign codes this import can reference, for the on-page guide.
            'accounts' => $client->socialAccounts()->get()
                ->map(fn (SocialAccount $account) => [
                    'handle' => $account->handle,
                    'platform' => $account->platform->label(),
                ])->all(),
            'campaignCodes' => $client->campaigns()->whereNotNull('code')
                ->orderBy('code')->pluck('code')->all(),
        ]);
    }

    public function preview(Request $request, Client $client, ParseBulkPostImportAction $parse): JsonResponse
    {
        $this->authorize('create', [Post::class, $client]);

        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:2048']]);

        $result = $parse($client, (string) $request->file('file')?->get());
        $handles = $this->handleMap($client);

        return response()->json([
            'rows' => array_map(fn (BulkPostRow $row) => [
                'row' => $row->rowNumber,
                'caption' => $row->caption,
                'channels' => array_map(fn (int $id) => $handles[$id] ?? '#'.$id, $row->accountIds),
                'date' => $row->scheduledLocalDate,
                'time' => $row->scheduledLocalTime,
                'campaign_id' => $row->campaignId,
                'format' => $row->format,
            ], $result->rows),
            'errors' => $result->errors,
            'valid_count' => count($result->rows),
            'error_count' => count($result->errors),
        ]);
    }

    public function store(Request $request, Client $client, ParseBulkPostImportAction $parse, CreatePostsInBulkAction $create): RedirectResponse
    {
        $this->authorize('create', [Post::class, $client]);

        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:2048']]);

        $result = $parse($client, (string) $request->file('file')?->get());

        // All-or-nothing: a single bad row blocks the whole batch (nothing is partially created).
        if ($result->hasErrors() || $result->rows === []) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $result->rows === []
                ? __('No valid rows to import.')
                : __('Fix the flagged rows and upload again — nothing was imported.')]);

            return back();
        }

        $created = $create($client, new Collection($result->rows), $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice(':count post imported as a draft.|:count posts imported as drafts.', $created, ['count' => $created])]);

        return to_route('clients.posts.index', $client);
    }

    public function template(Client $client): StreamedResponse
    {
        $this->authorize('create', [Post::class, $client]);

        return response()->streamDownload(function () {
            $handle = fopen('php://output', 'wb');

            if ($handle === false) {
                return;
            }

            fputcsv($handle, ParseBulkPostImportAction::COLUMNS);
            // One example row so the format is obvious.
            fputcsv($handle, [
                'Launch day! Doors open at 9.', 'launch opening', '@yourhandle;@otherhandle',
                now()->addWeek()->toDateString(), '09:00', '', 'Link in bio', 'https://example.com', '',
            ]);

            fclose($handle);
        }, 'graphy-import-template.csv', ['Content-Type' => 'text/csv']);
    }

    /**
     * @return array<int, string>
     */
    private function handleMap(Client $client): array
    {
        return $client->socialAccounts()->get()
            ->mapWithKeys(fn (SocialAccount $account) => [$account->id => $account->handle])
            ->all();
    }
}
