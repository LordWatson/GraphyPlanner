<?php

namespace App\Services\Workspace;

use App\Enums\ConnectionStatus;
use App\Enums\PostStatus;
use App\Models\Client;
use App\Models\Organization;

/**
 * Step B-2.2 — the business-onboarding checklist, computed from real data every time rather than
 * from stored flags, so it can never drift from the workspace's actual state (a user who connects
 * an account outside the wizard still sees that step tick). `organizations.onboarding_completed_at`
 * records only the "finished / skipped" decision, not the per-item progress.
 *
 * Each item is one small, indexed existence check scoped to the primary brand, so the whole summary
 * is a handful of cheap queries — safe to call on every `/welcome-setup` render and on the Today
 * dashboard (B-4.3).
 */
class OnboardingProgress
{
    public const BRAND = 'brand';

    public const ACCOUNTS = 'accounts';

    public const FIRST_POST = 'first_post';

    public const TEAM = 'team';

    /**
     * @return array{items: list<array{key: string, label: string, description: string, done: bool, optional: bool}>, requiredComplete: bool, completedAt: string|null}
     */
    public function for(Organization $organization): array
    {
        $brand = $organization->primaryBrand()->with('brandBrain')->first();

        $items = [
            [
                'key' => self::BRAND,
                'label' => 'Set up your brand',
                'description' => 'Tell us what you do and your voice, so captions and ideas sound like you.',
                'done' => $this->brandReady($brand),
                'optional' => false,
            ],
            [
                'key' => self::ACCOUNTS,
                'label' => 'Connect an account',
                'description' => 'Link Instagram, Facebook, TikTok or another platform to publish.',
                'done' => $this->hasConnectedAccount($brand),
                'optional' => false,
            ],
            [
                'key' => self::FIRST_POST,
                'label' => 'Line up your first post',
                'description' => 'Schedule or publish a post to get going.',
                'done' => $this->hasScheduledOrPublishedPost($brand),
                'optional' => false,
            ],
            [
                'key' => self::TEAM,
                'label' => 'Invite a teammate',
                'description' => 'Optional — add someone to help plan and post.',
                'done' => $this->hasTeammate($organization),
                'optional' => true,
            ],
        ];

        $requiredComplete = collect($items)
            ->where('optional', false)
            ->every(fn (array $item) => $item['done']);

        return [
            'items' => $items,
            'requiredComplete' => $requiredComplete,
            'completedAt' => $organization->onboarding_completed_at?->toIso8601String(),
        ];
    }

    /**
     * The brand profile has enough to generate on-brand content: the owner said what they do, or the
     * Brand Brain has a voice.
     */
    private function brandReady(?Client $brand): bool
    {
        if ($brand === null) {
            return false;
        }

        if (filled($brand->industry)) {
            return true;
        }

        $voice = $brand->brandBrain->voice ?? [];

        return filled($voice['tone'] ?? null) || filled($voice['personality'] ?? null);
    }

    private function hasConnectedAccount(?Client $brand): bool
    {
        return $brand !== null && $brand->socialAccounts()
            ->where('connection_status', ConnectionStatus::Connected->value)
            ->exists();
    }

    private function hasScheduledOrPublishedPost(?Client $brand): bool
    {
        return $brand !== null && $brand->posts()
            ->whereIn('status', [
                PostStatus::Scheduled->value,
                PostStatus::Publishing->value,
                PostStatus::Published->value,
                PostStatus::PartiallyPublished->value,
            ])
            ->exists();
    }

    private function hasTeammate(Organization $organization): bool
    {
        return $organization->users()->count() > 1
            || $organization->staffInvitations()->exists();
    }
}
