<?php

namespace App\Support\WhatsApp;

/**
 * Step W-1.4 — every WhatsApp message this app can send, and the exact copy submitted for
 * approval.
 *
 * Business-initiated WhatsApp is templates only: Meta approves the wording in advance and refuses
 * anything else outside the 24-hour reply window. So the set of messages we can send is a closed
 * list, and that makes it an enum — a notification cannot invent a template, and
 * `WhatsAppTemplateMessage` refuses to fill one with the wrong variables.
 *
 * `sampleBody()` is the text that goes to Twilio's Content Template Builder, kept next to the code
 * that fills it so the two cannot drift. `php artisan whatsapp:templates` prints the submission
 * list. Twilio returns a `ContentSid` (`HX…`) per approved template, which is per-account and so
 * lives in config (`services.whatsapp.templates`), not here.
 */
enum MessageTemplate: string
{
    /** Step W-1.1 — the one-time code that verifies somebody's own number. */
    case VerifyCode = 'wa_verify_code';

    /** Step W-2.1 — the headline message: a client's post is waiting for their approval. */
    case PostWaitingClient = 'post_waiting_client';

    /** Step W-2.2 — a post goes out soon and somebody should look at it. */
    case PostScheduledReminder = 'post_scheduled_reminder';

    /** Step W-2.3 — the client approved, or asked for changes. */
    case PostDecided = 'post_decided';

    /** Step W-2.4 — the alarm. A publish failed. */
    case PostPublishFailed = 'post_publish_failed';

    /** Step W-2.5 — an approval has been sitting past the organization's SLA. */
    case ApprovalOverdue = 'approval_overdue';

    /** Step W-2.5 — an invoice has been issued to a client. */
    case InvoiceIssued = 'invoice_issued';

    /** Step W-2.5 — a campaign brief is ready for the client to read. */
    case CampaignBriefReady = 'campaign_brief_ready';

    /** Step W-2.6 — the daily digest, one message instead of many. */
    case DailyDigest = 'daily_digest';

    /** Step S-3.3 — a Story with a link or stickers is due and needs posting by hand. */
    case StoryReadyToPost = 'story_ready_to_post';

    /**
     * The generic fallback: any notification wired to WhatsApp that has not been given copy of its
     * own. Deliberately bland, because it has to be true of anything.
     */
    case Notification = 'alima_notification';

    /**
     * The template's variables, in `{{1}}`-first order. The names are the contract between the
     * approved copy and the code that fills it.
     *
     * @return list<string>
     */
    public function variables(): array
    {
        return match ($this) {
            self::VerifyCode => ['code'],
            self::PostWaitingClient => ['client_name', 'post_summary', 'review_url'],
            self::PostScheduledReminder => ['post_summary', 'going_out_at', 'url'],
            self::PostDecided => ['client_name', 'decision', 'post_summary', 'url'],
            self::PostPublishFailed => ['post_summary', 'targets', 'url'],
            self::ApprovalOverdue => ['post_summary', 'waiting_for', 'url'],
            self::InvoiceIssued => ['client_name', 'amount', 'due_date', 'url'],
            self::CampaignBriefReady => ['campaign_name', 'url'],
            self::DailyDigest => ['summary', 'url'],
            self::StoryReadyToPost => ['account', 'due_at', 'url'],
            self::Notification => ['title', 'body', 'url'],
        };
    }

    /**
     * The exact text submitted to Twilio for WhatsApp approval, as a `twilio/text` content type.
     *
     * Two of Meta's review rules shape this copy, and breaking either gets the template rejected
     * rather than failing at send time:
     *
     * - a body may not **end** with a variable (a "dangling parameter"), so every one of these
     *   closes with fixed text — which is why the links read "Open {{n}} to …" rather than
     *   trailing off the end of the sentence;
     * - two variables may not sit next to each other with only whitespace between them.
     */
    public function sampleBody(): string
    {
        return match ($this) {
            self::VerifyCode => 'Your Alima verification code is {{1}}. It expires in 10 minutes. If you did not ask for it, ignore this message.',
            self::PostWaitingClient => 'Hi {{1}}, a post is ready for your approval: "{{2}}". Open {{3}} to review and approve it.',
            self::PostScheduledReminder => 'Reminder: "{{1}}" is scheduled to go out {{2}}. Open {{3}} to check or change it.',
            self::PostDecided => '{{1}} has {{2}} the post "{{3}}". Open {{4}} to see it.',
            self::PostPublishFailed => 'A post did not publish: "{{1}}" failed on {{2}}. Open {{3}} to retry it.',
            self::ApprovalOverdue => 'Still waiting on approval for "{{1}}": {{2}}. Open {{3}} to nudge it.',
            self::InvoiceIssued => 'Hi {{1}}, your invoice for {{2}} is ready and due {{3}}. Open {{4}} to view and pay it.',
            self::CampaignBriefReady => 'Your brief for the {{1}} campaign is ready. Open {{2}} to read it.',
            self::DailyDigest => 'Your Alima update: {{1}}. Open {{2}} to see everything.',
            self::StoryReadyToPost => 'Time to post: the story for {{1}} is ready, due {{2}}. Open {{3}} to post it to Instagram.',
            self::Notification => '{{1}}: {{2}}. Open {{3}} for the details.',
        };
    }

    /**
     * Twilio's identifier for the approved template, or null when this organization has not had it
     * approved yet. A send without one is refused rather than guessed at.
     */
    public function contentSid(): ?string
    {
        $sid = config('services.whatsapp.templates.'.$this->value);

        return is_string($sid) && $sid !== '' ? $sid : null;
    }

    /**
     * Fill the template. Keys must be exactly `variables()`, which is what stops a notification
     * from sending copy with a hole in it.
     *
     * @param  array<string, string|int|float|null>  $values
     */
    public function fill(array $values): WhatsAppTemplateMessage
    {
        return new WhatsAppTemplateMessage($this, $values);
    }
}
