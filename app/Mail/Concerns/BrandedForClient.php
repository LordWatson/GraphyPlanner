<?php

namespace App\Mail\Concerns;

use App\Models\Organization;
use App\Services\Branding\BrandingResolver;
use App\Support\Branding\Branding;
use Illuminate\Mail\Mailable;

/**
 * Step GTM-3.1 — makes one mailable carry an agency's branding. Used by the emails a **client**
 * receives; the agency's own notifications (digests, reminders, ops alerts) stay Alima's.
 *
 * `brandingFor()` does two things and returns the view data for both partials:
 *
 *  - sets the **display name** and the reply-to, so a reply reaches the agency rather than us;
 *  - hands the resolved {@see Branding} and the logo URL to
 *    `emails.partials.brand-header` / `brand-footer`.
 *
 * The envelope **from address never changes**: it stays our own verified sending domain, because
 * SPF and DKIM are signed for that domain and a spoofed from-address would land the agency's mail
 * in spam. A per-agency sending domain is a bigger piece of work (see `settings.md`).
 *
 * @phpstan-require-extends Mailable
 */
trait BrandedForClient
{
    /**
     * @return array{branding: Branding, brandLogoUrl: string|null}
     */
    protected function brandingFor(?Organization $organization): array
    {
        $resolver = app(BrandingResolver::class);
        $branding = $resolver->for($organization);

        if ($branding->isWhiteLabel) {
            $this->from((string) config('mail.from.address'), $branding->senderName ?? $branding->name);
        }

        if ($branding->replyTo !== null) {
            $this->replyTo($branding->replyTo, $branding->senderName ?? $branding->name);
        }

        return ['branding' => $branding, 'brandLogoUrl' => $resolver->mailLogoUrl($organization)];
    }
}
