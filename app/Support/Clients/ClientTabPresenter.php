<?php

namespace App\Support\Clients;

use App\Models\Campaign;
use App\Models\ClientContact;
use App\Models\ClientInvitation;
use App\Models\Invoice;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Support\Campaigns\CampaignPresenter;
use App\Support\Invoices\InvoicePresenter;

/**
 * Step I-2.4 — the row shapes of the client page's tabs (moved out of `ClientController` so each
 * tab controller, and any other page listing the same records, renders them identically).
 * Every row carries the viewer's abilities on it under `can`.
 */
final class ClientTabPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function socialAccount(SocialAccount $socialAccount, User $user): array
    {
        return [
            'id' => $socialAccount->id,
            'platform' => $socialAccount->platform->value,
            'handle' => $socialAccount->handle,
            'display_name' => $socialAccount->display_name,
            'timezone' => $socialAccount->timezone,
            'language' => $socialAccount->language,
            'country' => $socialAccount->country,
            'default_location' => $socialAccount->default_location,
            'posting_windows' => $socialAccount->posting_windows,
            'persona_override' => $socialAccount->persona_override,
            'connection_status' => $socialAccount->connection_status->value,
            // Step M-2.6: the registry settings (Pinterest board, …) and which required ones are missing.
            'platform_settings' => (object) ($socialAccount->platform_settings ?? []),
            'missing_settings' => array_values(array_filter(
                $socialAccount->platform->spec()->requiredSettings,
                fn (string $setting) => blank($socialAccount->platform_settings[$setting] ?? null),
            )),
            // Step M-2.7: the last health check and the token's expiry.
            'last_health_check_at' => $socialAccount->last_health_check_at?->toIso8601String(),
            'last_health_error' => $socialAccount->last_health_error,
            'token_expires_at' => $socialAccount->meta_access_token_expires_at?->toIso8601String(),
            'token_expiry' => $socialAccount->tokenExpiryPhrase(),
            'can' => [
                'update' => $user->can('update', $socialAccount),
                'delete' => $user->can('delete', $socialAccount),
                'connect' => $user->can('update', $socialAccount),
            ],
        ];
    }

    /**
     * @return array{id: int, platform: string, handle: string, timezone: string}
     */
    public static function targetAccount(SocialAccount $socialAccount): array
    {
        return [
            'id' => $socialAccount->id,
            'platform' => $socialAccount->platform->value,
            'handle' => $socialAccount->handle,
            'timezone' => $socialAccount->timezone,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function campaign(Campaign $campaign, User $user): array
    {
        return [
            'id' => $campaign->id,
            'name' => $campaign->name,
            'status' => $campaign->status->value,
            'start_date' => $campaign->start_date?->toDateString(),
            'end_date' => $campaign->end_date?->toDateString(),
            'goal' => $campaign->goal,
            'notes' => $campaign->notes,
            'can' => [
                'update' => $user->can('update', $campaign),
                'delete' => $user->can('delete', $campaign),
            ],
        ];
    }

    /**
     * Expects `targets.socialAccount` and `campaign` eager-loaded.
     *
     * @return array<string, mixed>
     */
    public static function post(Post $post, User $user): array
    {
        return [
            'id' => $post->id,
            'campaign_id' => $post->campaign_id,
            'campaign_name' => $post->campaign?->name,
            // Campaigns enhancement C-1.3: the chip (colour + code).
            'campaign' => $post->campaign ? CampaignPresenter::chip($post->campaign) : null,
            'status' => $post->status->value,
            'approval_mode' => $post->approval_mode->value,
            'master_caption' => $post->master_caption,
            'hashtags' => $post->hashtags,
            'targets' => $post->targets->map(fn ($target) => [
                'id' => $target->id,
                'social_account_id' => $target->social_account_id,
                'platform' => $target->socialAccount?->platform?->value,
                'handle' => $target->socialAccount?->handle,
                'scheduled_local_date' => $target->scheduled_local_date?->toDateString(),
                'scheduled_local_time' => $target->scheduled_local_time,
                'scheduled_at_utc' => $target->scheduled_at_utc?->toIso8601String(),
            ])->values(),
            'created_at' => $post->created_at?->toIso8601String(),
            'can' => [
                'update' => $user->can('update', $post),
                'delete' => $user->can('delete', $post),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function invoice(Invoice $invoice, User $user): array
    {
        return [
            ...InvoicePresenter::row($invoice),
            'can' => InvoicePresenter::abilities($invoice, $user),
        ];
    }

    /**
     * Deliberately never includes the token or its hash — only
     * `App\Actions\ClientInvitations\CreateClientInvitationAction` ever sees the plain token, and
     * it never gets persisted or rendered.
     *
     * @return array<string, mixed>
     */
    public static function invitation(ClientInvitation $invitation, User $user): array
    {
        return [
            'id' => $invitation->id,
            'email' => $invitation->email,
            'expires_at' => $invitation->expires_at->toIso8601String(),
            'accepted_at' => $invitation->accepted_at?->toIso8601String(),
            'revoked_at' => $invitation->revoked_at?->toIso8601String(),
            'is_pending' => $invitation->isPending(),
            'can' => [
                'delete' => $invitation->isPending() && $user->can('delete', $invitation),
            ],
        ];
    }

    /**
     * Step M-6.1 — a client contact row for the Portal access tab.
     *
     * @return array<string, mixed>
     */
    public static function contact(ClientContact $contact, User $user): array
    {
        return [
            'id' => $contact->id,
            'name' => $contact->name,
            'email' => $contact->email,
            'phone' => $contact->phone,
            'role' => $contact->role->value,
            'role_label' => $contact->role->label(),
            'receives_review_emails' => $contact->receives_review_emails,
            'receives_invoices' => $contact->receives_invoices,
            'can' => [
                'update' => $user->can('update', $contact),
                'delete' => $user->can('delete', $contact),
            ],
        ];
    }
}
