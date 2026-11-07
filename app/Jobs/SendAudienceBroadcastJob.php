<?php

namespace App\Jobs;

use App\Mail\AudienceMessageMail;
use App\Models\AudienceBroadcast;
use App\Support\Audience\BroadcastRecipients;
use App\Support\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Sends a broadcast the owner has already composed and committed.
 *
 * It used to be a `foreach` in the web request: one queue push per contact, so a 5,000-contact
 * list was 5,000 sequential writes before the response could be returned, and a bigger one hit
 * `max_execution_time` part-way through with no record of how far it got.
 *
 * Three things this does differently:
 *
 *  - **Recipients are resolved in chunks** (`chunkById`), so memory stays flat whatever the list size.
 *  - **Progress is recorded as it goes**, so the page can say "2,300 of 5,000 sent" and a retry can
 *    pick up rather than start again. A contact who already has a send recorded is skipped.
 *  - **Each chunk is delayed a little further than the last**, so a big list is handed to the mail
 *    provider at a steady rate instead of all at once. Thousands of simultaneous sends is how you
 *    collect 429s, and the reputation damage lands on the domain that also carries approval
 *    requests.
 *
 * Unique per broadcast, so a double dispatch cannot send it twice.
 */
class SendAudienceBroadcastJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 600;

    /**
     * Recipients resolved per query. Large enough that the list is a handful of queries, small
     * enough that one chunk's mails are a modest burst.
     */
    private const CHUNK = 500;

    /**
     * Seconds added to each successive chunk's delay, so the provider sees a steady stream.
     */
    private const CHUNK_DELAY_SECONDS = 20;

    public function __construct(public readonly int $broadcastId)
    {
        $this->onQueue(Queues::MAIL);
    }

    public function uniqueId(): string
    {
        return (string) $this->broadcastId;
    }

    public function handle(): void
    {
        /** @var AudienceBroadcast|null $broadcast */
        $broadcast = AudienceBroadcast::query()->withoutOrgScope()->find($this->broadcastId);

        if ($broadcast === null || $broadcast->finished_at !== null) {
            return;
        }

        $sent = (int) $broadcast->sent_count;
        $failed = (int) $broadcast->failed_count;
        $chunk = 0;

        // Resume past whatever an earlier attempt got through. Ordered by id, so "greater than the
        // last one handled" is exact rather than approximate.
        BroadcastRecipients::forBroadcast($broadcast)
            ->where('id', '>', (int) $broadcast->last_contact_id)
            ->chunkById(self::CHUNK, function ($contacts) use ($broadcast, &$sent, &$failed, &$chunk): void {
                $delay = now()->addSeconds($chunk * self::CHUNK_DELAY_SECONDS);
                $lastId = (int) $broadcast->last_contact_id;

                foreach ($contacts as $contact) {
                    try {
                        Mail::to($contact->email)->later($delay, new AudienceMessageMail($contact, null, $broadcast));
                        $sent++;
                    } catch (Throwable $e) {
                        $failed++;

                        Log::warning('Broadcast recipient could not be queued', [
                            'broadcast_id' => $broadcast->id,
                            'org_id' => $broadcast->org_id,
                            'exception' => $e::class,
                        ]);
                    }

                    $lastId = max($lastId, (int) $contact->id);
                }

                $chunk++;

                // After each chunk, so an interrupted run leaves an honest count and a resume point.
                $broadcast->update([
                    'sent_count' => $sent,
                    'failed_count' => $failed,
                    'last_contact_id' => $lastId,
                ]);
            });

        $broadcast->update(['sent_count' => $sent, 'failed_count' => $failed, 'finished_at' => now()]);

        Log::info('Audience broadcast delivered to the queue', [
            'broadcast_id' => $broadcast->id,
            'org_id' => $broadcast->org_id,
            'sent' => $sent,
            'failed' => $failed,
        ]);
    }
}
