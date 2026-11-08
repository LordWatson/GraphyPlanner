<?php

namespace App\Console\Commands;

use App\Support\WhatsApp\MessageTemplate;
use Illuminate\Console\Command;

/**
 * Step W-1.4 — print the message templates to submit to Twilio for WhatsApp approval, and say
 * which of them are already approved.
 *
 * Approval is a manual, days-long step outside this codebase, and the copy has to match exactly
 * what the code fills. This is what gets pasted into Twilio's Content Template Builder, and the
 * `ContentSid` it hands back goes into `services.whatsapp.templates`.
 */
class WhatsAppTemplatesCommand extends Command
{
    protected $signature = 'whatsapp:templates {--missing : Only the templates with no ContentSid yet}';

    protected $description = 'List the WhatsApp message templates to submit for approval';

    public function handle(): int
    {
        $missingOnly = (bool) $this->option('missing');
        $missing = 0;

        foreach (MessageTemplate::cases() as $template) {
            $sid = $template->contentSid();

            if ($sid === null) {
                $missing++;
            } elseif ($missingOnly) {
                continue;
            }

            $this->newLine();
            $this->line('<fg=cyan>'.$template->value.'</>  '.($sid ?? '<fg=yellow>not approved yet</>'));
            $this->line('  body:      '.$template->sampleBody());
            $this->line('  variables: '.implode(', ', array_map(
                fn (string $name, int $i) => '{{'.($i + 1).'}} = '.$name,
                $template->variables(),
                array_keys($template->variables()),
            )));
            $this->line('  env:       '.$this->envKeyFor($template));
        }

        $this->newLine();

        if ($missing === 0) {
            $this->info('Every template has an approved ContentSid.');

            return self::SUCCESS;
        }

        $this->warn($missing.' of '.count(MessageTemplate::cases()).' templates have no ContentSid yet.');
        $this->line('Create each one in Twilio (Content Template Builder) as a `twilio/text` template,');
        $this->line('submit it for WhatsApp approval, then put the HX… id in the env var above.');

        // Not an error: a deployment that only uses some of the templates is a normal state, and
        // a send with no SID is refused rather than failing at Twilio.
        return self::SUCCESS;
    }

    /**
     * Mirrors `config/services.php`, so the output can be pasted straight into an `.env`.
     */
    private function envKeyFor(MessageTemplate $template): string
    {
        return match ($template) {
            MessageTemplate::VerifyCode => 'TWILIO_TEMPLATE_VERIFY_CODE',
            MessageTemplate::PostWaitingClient => 'TWILIO_TEMPLATE_POST_WAITING_CLIENT',
            MessageTemplate::PostScheduledReminder => 'TWILIO_TEMPLATE_POST_SCHEDULED_REMINDER',
            MessageTemplate::PostDecided => 'TWILIO_TEMPLATE_POST_DECIDED',
            MessageTemplate::PostPublishFailed => 'TWILIO_TEMPLATE_POST_PUBLISH_FAILED',
            MessageTemplate::ApprovalOverdue => 'TWILIO_TEMPLATE_APPROVAL_OVERDUE',
            MessageTemplate::InvoiceIssued => 'TWILIO_TEMPLATE_INVOICE_ISSUED',
            MessageTemplate::CampaignBriefReady => 'TWILIO_TEMPLATE_CAMPAIGN_BRIEF_READY',
            MessageTemplate::DailyDigest => 'TWILIO_TEMPLATE_DAILY_DIGEST',
            MessageTemplate::Notification => 'TWILIO_TEMPLATE_NOTIFICATION',
        };
    }
}
