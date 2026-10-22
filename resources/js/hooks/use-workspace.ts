import { usePage } from '@inertiajs/react';
import type { Workspace } from '@/types';

/**
 * Step B-4 — the current workspace shell from the shared Inertia prop. Null for an org-less super
 * admin. `isBusiness` is the switch shared components use to choose business copy and layout.
 */
export function useWorkspace(): {
    workspace: Workspace | null;
    isBusiness: boolean;
    isAgency: boolean;
} {
    const { workspace } = usePage().props;

    return {
        workspace: workspace ?? null,
        isBusiness: workspace?.type === 'business',
        isAgency: workspace?.type === 'agency',
    };
}
