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
    platformOptions: Option[];
    suggestedPhases: {
        name: string;
        starts_on: string;
        ends_on: string;
        colour: string;
    }[];
    leadDays: number;
};
