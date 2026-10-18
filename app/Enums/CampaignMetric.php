<?php

namespace App\Enums;

/**
 * Step C-7.1 — what a campaign KPI measures. The first five come from data the app already has;
 * the engagement metrics are listed so agencies can set targets now, but report
 * `available: false` until engagement tracking (Phase 12 / I-10.1, I-10.4) lands — never a zero
 * that looks like a real result.
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

    public function isAvailable(): bool
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
