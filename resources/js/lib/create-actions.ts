import { router, usePage } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import { Building2, FileBarChart, Send, UserPlus } from 'lucide-react';
import { useMemo } from 'react';
import { useWorkspace } from '@/hooks/use-workspace';
import { toUrl } from '@/lib/utils';
import { create as createBusinessPost } from '@/routes/business/posts';
import { create as createAnalyticsReport } from '@/routes/analytics/reports';
import { create as createClient } from '@/routes/clients';
import { index as contentIndex } from '@/routes/content';
import { create as createOrganization } from '@/routes/super-admin/organizations';
import { create as createReport } from '@/routes/super-admin/reports';
import type { Auth } from '@/types';

/**
 * MOVE 4 (ux-overhaul): one Create. The set of "make something new" actions for the current
 * workspace + role, shared by the command palette's Create group and the header's New menu so the
 * two can never drift. Each action performs its own navigation (a visit, or a POST for the
 * empty-editor shortcut); callers close their own surface first.
 */
export type CreateAction = {
    id: string;
    label: string;
    hint: string;
    keywords: string;
    icon: LucideIcon;
    perform: () => void;
};

export function useCreateActions(): CreateAction[] {
    const { auth } = usePage<{ auth: Auth }>().props;
    const { isBusiness } = useWorkspace();

    return useMemo(() => {
        const visit = (href: Parameters<typeof toUrl>[0]) => () =>
            router.visit(toUrl(href));

        const role = auth.user.role ?? '';
        const isBilling = role === 'owner' || role === 'strategist';
        const canViewAnalytics = auth.can?.viewAnalytics === true;

        if (role === 'super_admin') {
            return [
                {
                    id: 'create-organization',
                    label: 'Onboard an organization',
                    hint: 'Set up a workspace and invite its owner.',
                    keywords: 'new organization org signup onboard',
                    icon: Building2,
                    perform: visit(createOrganization()),
                },
                {
                    id: 'create-report',
                    label: 'Build a report',
                    hint: 'Save an analytics view to re-run later.',
                    keywords: 'new report analytics saved',
                    icon: FileBarChart,
                    perform: visit(createReport()),
                },
            ];
        }

        if (isBusiness) {
            return [
                {
                    id: 'create-post',
                    label: 'New post',
                    hint: 'Start a fresh post for your brand.',
                    keywords: 'new post create draft schedule',
                    icon: Send,
                    perform: () => router.post(toUrl(createBusinessPost())),
                },
            ];
        }

        const actions: CreateAction[] = [];

        // Step M-5.2: producing roles can start a post from anywhere; the content hub's "New post"
        // picker chooses the client (per-client create is re-checked server-side).
        if (['owner', 'strategist', 'designer'].includes(role)) {
            actions.push({
                id: 'create-post',
                label: 'New post',
                hint: 'Start a post for any client.',
                keywords: 'new post create draft content',
                icon: Send,
                perform: visit(contentIndex()),
            });
        }

        if (isBilling) {
            actions.push({
                id: 'create-client',
                label: 'Add a client',
                hint: 'Start a new retainer on the roster.',
                keywords: 'new client brand onboard',
                icon: UserPlus,
                perform: visit(createClient()),
            });
        }

        // Saved reports are built by Owners/Strategists (`OrganizationReportPolicy::create`).
        if (isBilling && canViewAnalytics) {
            actions.push({
                id: 'create-analytics-report',
                label: 'Build a report',
                hint: 'Save an analytics view to re-run, export or email.',
                keywords: 'new report analytics saved export schedule',
                icon: FileBarChart,
                perform: visit(createAnalyticsReport()),
            });
        }

        return actions;
    }, [auth.user.role, auth.can?.viewAnalytics, isBusiness]);
}
