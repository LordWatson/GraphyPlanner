<?php

namespace App\Http\Controllers;

use App\Actions\Audience\ConfirmAudienceContactAction;
use App\Actions\Audience\UnsubscribeAudienceContactAction;
use App\Models\AudienceContact;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Step GTM-2.1 — the two links a contact owns, both on signed routes (`App\Support\Audience\
 * AudienceLinks`): the signature is the whole authorisation, so there is no session and no token
 * column. Thin: one Action each, one page with a different message.
 *
 * Unsubscribe is a plain `GET` on purpose. A one-click link in an email has to work from an email
 * client that will not post a form, and a confirmation step people have to find is a dark pattern.
 */
class AudienceLinkController extends Controller
{
    public function confirm(AudienceContact $contact, ConfirmAudienceContactAction $confirm): Response
    {
        $confirmed = $confirm($contact);

        return $this->page(
            $contact,
            $confirmed ? 'confirmed' : ($contact->unsubscribed_at !== null ? 'unsubscribed' : 'already-confirmed'),
        );
    }

    public function unsubscribe(AudienceContact $contact, UnsubscribeAudienceContactAction $unsubscribe): Response
    {
        $unsubscribe($contact);

        return $this->page($contact, 'unsubscribed');
    }

    private function page(AudienceContact $contact, string $state): Response
    {
        return Inertia::render('public/audience', [
            'state' => $state,
            'brandName' => $contact->client?->name,
            'email' => $contact->email,
        ]);
    }
}
