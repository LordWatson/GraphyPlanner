<?php

namespace App\Mail;

use App\Enums\AudienceTrigger;
use App\Models\AudienceBroadcast;
use App\Models\AudienceContact;
use App\Models\BioPage;
use App\Support\Audience\AudienceLinks;
use App\Support\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Step GTM-2.2 / GTM-2.3 — every audience email after the confirmation: the welcome, the purchase
 * follow-up, the abandoned-checkout nudge and the owner's own broadcasts. One mailable and one
 * blade because it is one email — a brand header, a headline, a body and at most one button — and
 * **every one of them carries the unsubscribe link**, which is why they share this class rather
 * than each getting their own.
 *
 * Exactly one of `$trigger` (an automated email, copy written here) or `$broadcast` (the owner's
 * own words) is given.
 */
class AudienceMessageMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public AudienceContact $contact,
        public ?AudienceTrigger $trigger = null,
        public ?AudienceBroadcast $broadcast = null,
    ) {
        $this->onQueue(Queues::MAIL);
    }

    public function build(): self
    {
        $brand = $this->contact->client?->name ?? config('app.name');
        $first = $this->contact->name === null ? null : trim(explode(' ', trim($this->contact->name))[0]);

        [$subject, $headline, $body, $ctaLabel, $ctaUrl] = $this->broadcast !== null
            ? [$this->broadcast->subject, $this->broadcast->subject, $this->broadcast->body, null, null]
            : $this->triggerCopy($brand);

        return $this->subject($subject)->view('emails.audience-message', [
            'brand' => $brand,
            'greeting' => $first === null || $first === '' ? __('Hello,') : __('Hi :name,', ['name' => $first]),
            'headline' => $headline,
            'body' => $body,
            'ctaLabel' => $ctaLabel,
            'ctaUrl' => $ctaUrl,
            'unsubscribeUrl' => AudienceLinks::unsubscribe($this->contact),
        ]);
    }

    /**
     * The copy for an automated trigger. Deliberately plain and short: it goes out in the
     * creator's name, so it says only what is true and nothing we'd be putting words in their
     * mouth for.
     *
     * @return array{0: string, 1: string, 2: string, 3: string|null, 4: string|null}
     */
    private function triggerCopy(string $brand): array
    {
        $storeUrl = $this->storeUrl();

        return match ($this->trigger) {
            AudienceTrigger::Purchase => [
                __('Thanks again from :brand', ['brand' => $brand]),
                __('Thanks again.'),
                __('Just checking in on what you bought — if anything is missing or did not work, replying to this email reaches :brand directly.', ['brand' => $brand]),
                $storeUrl === null ? null : __('See what else is there'),
                $storeUrl,
            ],
            AudienceTrigger::Abandoned => [
                __('You left something behind at :brand', ['brand' => $brand]),
                __('You left something behind.'),
                __('Your checkout did not finish, so nothing was charged. The link below picks up where you left off.'),
                $storeUrl === null ? null : __('Finish your order'),
                $storeUrl,
            ],
            default => [
                __('Welcome to :brand', ['brand' => $brand]),
                __('You are on the list.'),
                __('Thanks for confirming — you will hear from :brand when there is something worth sending.', ['brand' => $brand]),
                $storeUrl === null ? null : __('Have a look around'),
                $storeUrl,
            ],
        };
    }

    /**
     * The brand's own page, when it has a published one. Null rather than a guess: an email that
     * links nowhere is better than one that links to a 404.
     */
    private function storeUrl(): ?string
    {
        $page = BioPage::query()->where('client_id', $this->contact->client_id)->first();

        return $page === null || $page->published_at === null ? null : route('bio.show', $page->handle);
    }
}
