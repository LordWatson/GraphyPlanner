<?php

namespace App\Services\WhatsApp;

use App\Contracts\WhatsAppClient;
use App\Models\Organization;
use App\Support\WhatsApp\WhatsAppSendResult;
use App\Support\WhatsApp\WhatsAppTemplateMessage;

/**
 * Step W-0.1 — the safe no-op `WhatsAppClient`: never calls Meta, never sends, reports
 * not-configured. Bound by `WHATSAPP_CLIENT=null`, and what tests bind when they want to prove a
 * notification path is harmless with WhatsApp off.
 */
class NullWhatsAppClient implements WhatsAppClient
{
    public function sendTemplate(Organization $organization, string $toPhone, WhatsAppTemplateMessage $message): WhatsAppSendResult
    {
        return WhatsAppSendResult::notConfigured();
    }

    public function sendSessionText(Organization $organization, string $toPhone, string $body): WhatsAppSendResult
    {
        return WhatsAppSendResult::notConfigured();
    }

    public function verifyCredentials(Organization $organization): bool
    {
        return false;
    }
}
