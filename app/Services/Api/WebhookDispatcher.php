<?php

namespace App\Services\Api;

use App\Enums\WebhookEvent;
use App\Jobs\DeliverWebhookJob;
use App\Models\Organization;
use App\Models\Scopes\ClientAccessScope;
use App\Models\Scopes\OrganizationScope;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;

/**
 * The one way an event leaves Graphy for somebody else's system.
 *
 * Callers say "this happened, here is the organization and the facts" and nothing more: whether
 * anyone is listening, how it is signed, how it retries and what happens when it fails are all
 * decided here. That keeps the emitting code — a status transition, a paid invoice — ignorant of
 * webhooks entirely, which is what stops this becoming delivery logic sprinkled across Actions.
 *
 * Recorded first, delivered second. The row is the commitment; the HTTP request is an attempt at
 * it. That ordering is what makes a failed delivery something you can see and replay rather than
 * something that silently did not happen — the same lesson as the Connect webhook's claim row
 * (audit finding 2).
 */
class WebhookDispatcher
{
    /**
     * Queue this event to every endpoint of `$organization` that asked for it.
     *
     * @param  array<string, mixed>  $payload
     * @return int how many endpoints it went to
     */
    public function dispatch(Organization $organization, WebhookEvent $event, array $payload): int
    {
        // Unscoped on purpose: this runs from queue workers and scheduled commands where nobody is
        // signed in, and from requests where the acting member may be restricted to some clients.
        // Neither should change who the *organization* has told us to notify.
        $endpoints = WebhookEndpoint::query()
            ->withoutGlobalScope(OrganizationScope::class)
            ->withoutGlobalScope(ClientAccessScope::class)
            ->where('org_id', $organization->id)
            ->whereNull('disabled_at')
            ->get()
            ->filter(fn (WebhookEndpoint $endpoint) => $endpoint->wants($event->value));

        foreach ($endpoints as $endpoint) {
            $delivery = WebhookDelivery::query()->create([
                'webhook_endpoint_id' => $endpoint->id,
                'org_id' => $organization->id,
                'event' => $event->value,
                'payload' => [
                    'event' => $event->value,
                    // The moment it happened, not the moment it was delivered — a retry hours later
                    // must not look like a fresh event to the receiver.
                    'occurred_at' => now()->toIso8601String(),
                    'organization_id' => $organization->id,
                    'data' => $payload,
                ],
            ]);

            DeliverWebhookJob::dispatch($delivery->id);
        }

        return $endpoints->count();
    }
}
