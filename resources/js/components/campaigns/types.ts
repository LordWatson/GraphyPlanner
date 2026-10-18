/**
 * Prop shapes for the campaigns module — mirrors `App\Support\Campaigns\CampaignPresenter` and
 * `BuildCampaignWorkspaceHeaderAction` on the server.
 */

export type Option = { value: string; label: string };

export type CampaignTimeline = {
    state: 'upcoming' | 'live' | 'ended' | 'undated';
    sentence: string;
    days_to_launch: number | null;
    days_left: number | null;
    ran_for: number | null;
    ended_days_ago: number | null;
};

export type HealthFinding = {
    level: 'red' | 'amber' | 'green';
    code: string;
    reason: string;
};

export type CampaignHealth = {
    status: 'red' | 'amber' | 'green';
    label: string;
    reason: string;
    findings: HealthFinding[];
};

export type CampaignReadiness = {
    percent: number | null;
    label: string;
    blockers: { code: string; reason: string }[];
    counts: Record<string, number>;
};

export type CampaignBilling = {
    billing_type: string | null;
    billing_type_label: string | null;
    fee_amount: string | null;
    invoiced_amount: string;
    paid_amount: string;
    /** USD estimate from `ai_usage_logs` (C-8.3), not the invoice currency. */
    ai_cost_estimate: number | null;
    is_billable: boolean;
};

export type CampaignChipData = {
    id: number;
    name: string;
    code: string | null;
    colour: string | null;
};

export type StaffCampaign = CampaignChipData & {
    status: string;
    status_label: string;
    start_date: string | null;
    end_date: string | null;
    goal: string | null;
    notes: string | null;
    objective: string | null;
    objective_label: string | null;
    platforms: string[];
    hashtags: string[];
    default_link_url: string | null;
    utm_campaign: string | null;
    owner: { id: number; name: string | null } | null;
    client: { id: number; name: string | null };
    timeline: CampaignTimeline;
    billing: CampaignBilling | null;
    transitions: { to: string; label: string }[];
    health?: CampaignHealth | null;
    readiness?: CampaignReadiness | null;
};

export type WorkspaceTab =
    | 'overview'
    | 'brief'
    | 'plan'
    | 'board'
    | 'assets'
    | 'approvals'
    | 'results'
    | 'activity';

/** What every workspace tab page receives from `BuildCampaignWorkspaceHeaderAction`. */
export type CampaignWorkspaceProps = {
    campaign: StaffCampaign & {
        health: CampaignHealth;
        readiness: CampaignReadiness;
    };
    tab: WorkspaceTab;
    tabs: WorkspaceTab[];
    can: {
        update: boolean;
        delete: boolean;
        transition: boolean;
        produce: boolean;
        viewBilling: boolean;
        useAi: boolean;
        /** C-9.4: permission and feature, folded together server-side. */
        saveTemplate: boolean;
        aiPlanner: boolean;
        wrapReports: boolean;
        invoice: boolean;
    };
    counts: {
        posts: number;
        deliverables: number;
        phases: number;
        assets: number;
        awaitingClient: number;
        inReview: number;
        scheduled: number;
    };
    /** C-5.4: live preview links (editors only; empty otherwise). */
    shareLinks: CampaignShareLink[];
};

export type CampaignShareLink = {
    id: number;
    created_by: string | null;
    created_at: string | null;
    expires_at: string;
    view_count: number;
    last_viewed_at: string | null;
};

export type CampaignPost = {
    id: number;
    status: string;
    status_label: string;
    master_caption: string | null;
    targets: { platform: string | null; handle: string | null }[];
    scheduled_local_date: string | null;
    scheduled_local_time: string | null;
    scheduled_at_utc: string | null;
    deliverable_id: number | null;
    phase_id: number | null;
    updated_at: string | null;
};

export type Phase = {
    id: number;
    name: string;
    starts_on: string | null;
    ends_on: string | null;
    colour: string | null;
    sort: number;
};

export type AssetRequirement = { label: string; met: boolean };

export type Deliverable = {
    id: number;
    phase_id: number | null;
    title: string;
    platform: string;
    format: string | null;
    pillar: string | null;
    planned_for: string | null;
    planned_time: string | null;
    notes: string | null;
    asset_requirements: AssetRequirement[];
    assignee: { id: number; name: string } | null;
    post_id: number | null;
    state: string;
    state_label: string;
    due_on: string | null;
    is_overdue: boolean;
};

export type CampaignComment = {
    id: number;
    body: string;
    internal_only?: boolean;
    author: string;
    is_client: boolean;
    created_at: string | null;
};

export type ActivityItem = {
    key: string;
    source: 'campaign' | 'post';
    event: string;
    summary: string;
    from: string | null;
    to: string | null;
    note: string | null;
    user: string | null;
    post: { id: number; caption: string | null } | null;
    created_at: string;
};
