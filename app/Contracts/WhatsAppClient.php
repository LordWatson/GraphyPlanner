<?php

namespace App\Contracts;

use App\Models\Organization;
use App\Support\WhatsApp\WhatsAppSendResult;
use App\Support\WhatsApp\WhatsAppTemplateMessage;

/**
 * Step W-0.1 — the swappable WhatsApp provider seam, same shape as `PublishAdapter`: Meta's
 * Business Cloud API is the first implementation (`CloudApiWhatsAppClient`) and a BSP (Twilio,
 * 360dialog) can drop in behind this later without touching a caller.
 *
 * Every method is best-effort and returns a result rather than throwing: a WhatsApp failure must
 * never break the thing that triggered it (the bell and the email stay the source of truth), so
 * the caller decides what to do with a failed `WhatsAppSendResult`.
 */
interface WhatsAppClient
{
    /**
     * Send a Meta-approved message template. The only way to start a conversation: outside the
     * 24-hour customer-service window Meta refuses anything else.
     */
    public function sendTemplate(Organization $organization, string $toPhone, WhatsAppTemplateMessage $message): WhatsAppSendResult;

    /**
     * Send free text. Valid *only* inside the 24-hour window opened by the recipient's own last
     * message, so this is for replies — never for a business-initiated notification.
     */
    public function sendSessionText(Organization $organization, string $toPhone, string $body): WhatsAppSendResult;

    /**
     * Whether this organization's credentials actually work ("Test connection"). Never sends.
     */
    public function verifyCredentials(Organization $organization): bool;
}
