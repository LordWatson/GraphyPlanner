<?php

namespace App\Enums;

/**
 * Step C-7.1 — what a campaign KPI measures. The first five come from the app's own workflow data;
 * the engagement metrics (Step M-3.1) come from synced platform readings and report
 * `available: false` until a reading exists (`CampaignKpiService`) — never a zero that looks like
 * a real result.
 */
enum CampaignMetric: string
{
    case PostsPublished = 'posts_published';
    case OnTimeRate = 'on_time_rate';
    case ApprovalTurnaround = 'approval_turnaround';
    case FirstTimeApprovalRate = 'first_time_approval_rate';
    case DeliverablesCompleted = 'deliverables_completed';
    case Reach = 'reach';
    case Impressions = 'impressions';
    case EngagementRate = 'engagement_rate';
    case FollowerGrowth = 'follower_growth';
    case LinkClicks = 'link_clicks';

    public function label(): string
    {
        return match ($this) {
            self::PostsPublished => 'Posts published',
            self::OnTimeRate => 'On-time rate',
            self::ApprovalTurnaround => 'Approval turnaround',
            self::FirstTimeApprovalRate => 'First-time approval rate',
            self::DeliverablesCompleted => 'Deliverables completed',
            self::Reach => 'Reach',
            self::Impressions => 'Impressions',
            self::EngagementRate => 'Engagement rate',
            self::FollowerGrowth => 'Follower growth',
            self::LinkClicks => 'Link clicks',
        };
    }

    public function unit(): string
    {
        return match ($this) {
            self::OnTimeRate, self::FirstTimeApprovalRate, self::EngagementRate => 'percent',
            self::ApprovalTurnaround => 'hours',
            default => 'count',
        };
    }

    /**
     * Lower is better (turnaround); everything else is "reach the target or beat it".
     */
    public function lowerIsBetter(): bool
    {
        return $this === self::ApprovalTurnaround;
    }

    /**
     * Measured from the app's own workflow data, so always available. The engagement metrics
     * depend on synced readings and are decided per campaign by `CampaignKpiService`.
     */
    public function isWorkflowMetric(): bool
    {
        return in_array($this, [
            self::PostsPublished,
            self::OnTimeRate,
            self::ApprovalTurnaround,
            self::FirstTimeApprovalRate,
            self::DeliverablesCompleted,
        ], true);
    }
}
