<?php

namespace App\Actions\Audience;

use App\Enums\AudienceSource;
use App\Mail\AudienceConfirmMail;
use App\Models\Client;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Step GTM-2.1 — bring a list the creator already has into Alima. **An import never grants
 * consent**, whatever the file says: each new contact lands unconfirmed and is sent the same
 * confirmation email a sign-up gets, so a bought or scraped list is worth nothing here.
 *
 * `shortcut: the import takes pasted CSV text rather than an uploaded file, upgrade when somebody
 * asks` — a creator can open their export and copy it, and this way the import needs no upload
 * validation, virus scan or storage.
 *
 * Accepts `email` or `email,name` per line, ignores a header row, and skips anything that isn't an
 * address rather than failing the whole paste over one bad line.
 */
class ImportAudienceContactsAction
{
    /** A paste, not a migration tool. Anything bigger wants a real import pipeline. */
    public const MAX_ROWS = 500;

    public function __construct(private readonly CaptureAudienceContactAction $capture) {}

    /**
     * @return array{imported: int, already_known: int, skipped: int}
     */
    public function __invoke(Client $brand, string $csv): array
    {
        $imported = 0;
        $alreadyKnown = 0;
        $skipped = 0;

        foreach (array_slice(preg_split('/\r\n|\r|\n/', $csv) ?: [], 0, self::MAX_ROWS) as $line) {
            $cells = array_map(fn (string $cell) => trim($cell, " \t\"'"), explode(',', $line));
            $email = strtolower($cells[0] ?? '');

            if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                // A header row ("email,name") lands here too, which is why it isn't special-cased.
                $skipped++;

                continue;
            }

            $contact = ($this->capture)($brand, $email, $cells[1] ?? null, AudienceSource::Import);

            if (! $contact->wasRecentlyCreated) {
                $alreadyKnown++;

                continue;
            }

            $imported++;
            Mail::to($contact->email)->send(new AudienceConfirmMail($contact));
        }

        Log::info('Audience contacts imported', [
            'org_id' => $brand->org_id,
            'client_id' => $brand->id,
            'imported' => $imported,
            'already_known' => $alreadyKnown,
            'skipped' => $skipped,
        ]);

        return ['imported' => $imported, 'already_known' => $alreadyKnown, 'skipped' => $skipped];
    }
}
