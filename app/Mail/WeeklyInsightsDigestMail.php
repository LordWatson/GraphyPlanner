<?php

namespace App\Mail;

use App\Models\Client;
use App\Support\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Step B-8.2 — the weekly Insights digest for a business/creator workspace: a short Monday email
 * with last week's headline numbers and the plain-language AI takeaways. Opt-out lives on
 * `organizations.insights_digest_opt_out` (Workspace settings). Never contains agency language.
 */
class WeeklyInsightsDigestMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * @param  list<string>  $takeaways
     * @param  array{streak: int, published: int, reach: int|null, followers: int|null, period_days: int}  $headline
     */
    public function __construct(
        public Client $brand,
        public array $takeaways,
        public array $headline,
        public string $insightsUrl,
    ) {
        $this->onQueue(Queues::MAIL);
    }

    public function build(): self
    {
        return $this
            ->subject("Your week on social — {$this->brand->name}")
            ->view('emails.weekly-insights-digest');
    }
}
