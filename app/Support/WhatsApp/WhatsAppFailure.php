<?php

namespace App\Support\WhatsApp;

/**
 * Step W-0.2 — why a send failed, in the only four shapes a caller behaves differently about.
 */
enum WhatsAppFailure: string
{
    case None = 'none';

    /** Worth another attempt: a timeout, a 5xx, a rate limit. */
    case Transient = 'transient';

    /**
     * Not worth another attempt, ever: not a WhatsApp number, or its owner opted out. Step W-1.3
     * turns this into a suppression so nothing tries again.
     */
    case Permanent = 'permanent';

    /**
     * The 24-hour reply window has closed and the send was not a template Meta will accept
     * outside it. Step W-3.2 makes sure the email went instead.
     */
    case Window = 'window';

    /** Nobody was called: this organization has no WhatsApp credentials, or no approved template. */
    case NotConfigured = 'not_configured';
}
