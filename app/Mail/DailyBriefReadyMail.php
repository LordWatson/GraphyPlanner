<?php

namespace App\Mail;

use App\Models\Client;
use App\Models\DailyBrief;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to a client's organization Owners/Strategists when a new Daily Brief (Step 2.5) with
 * fresh AI trends/ideas is ready, respecting the quiet hours window configured in
 * `config('ai.daily_briefs')` — see `GenerateDailyBriefsAction`.
 */
class DailyBriefReadyMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Client $client,
        public DailyBrief $brief,
    ) {}

    public function build(): self
    {
        return $this
            ->subject("New daily brief for {$this->client->name}")
            ->view('emails.daily-brief-ready');
    }
}
