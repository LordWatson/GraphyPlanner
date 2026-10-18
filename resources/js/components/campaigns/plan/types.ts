import type { Deliverable, Option, Phase } from '@/components/campaigns/types';

export type PlanAccount = {
    id: number;
    platform: string;
    handle: string;
    connected: boolean;
};

/** Props `GetCampaignPlanAction` adds to the Plan tab. */
export type PlanData = {
    phases: Phase[];
    deliverables: Deliverable[];
    accounts: PlanAccount[];
    team: { id: number; name: string }[];
    pillars: string[];
    /** Only the client's channels (accounts on file, publishing switched on). */
    platformOptions: Option[];
    /** Every platform's label, for columns of slots on a channel that's since gone. */
    platformLabels: Record<string, string>;
    suggestedPhases: {
        name: string;
        starts_on: string;
        ends_on: string;
        colour: string;
    }[];
    leadDays: number;
};
