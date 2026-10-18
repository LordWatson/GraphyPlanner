import { router } from '@inertiajs/react';
import { CheckCheck, FileCheck2, Send, Undo2 } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { CampaignLayout } from '@/components/campaigns/campaign-layout';
import { CampaignPostRow } from '@/components/campaigns/campaign-post-row';
import type {
    CampaignPost,
    CampaignWorkspaceProps,
} from '@/components/campaigns/types';
import { EmptyState, Panel } from '@/components/page';
import { StatusPill } from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Spinner } from '@/components/ui/spinner';
import { postJson } from '@/lib/campaigns';
import { index as campaignsIndex } from '@/routes/campaigns';
import { bulk as bulkPosts } from '@/routes/campaigns/posts';
import { edit as editPost } from '@/routes/posts';

type ApprovalPost = CampaignPost & {
    can: { submit: boolean; sendToClient: boolean; approve: boolean };
};

type ApprovalsProps = CampaignWorkspaceProps & {
    groups: { status: string; label: string; posts: ApprovalPost[] }[];
    brief: { status: string; status_label: string } | null;
};

type BulkResult = { done: number[]; skipped: { id: number; reason: string }[] };

const GROUP_COPY: Record<string, string> = {
    changes_requested: 'The client asked for changes. Fix these first.',
    internal_review: 'Waiting on the studio. Approve, or send to the client.',
    waiting_client: 'With the client. Nudge them if it’s been a while.',
    draft: 'Still being written. Submit them for review when they’re ready.',
};

/**
 * Step C-4.6 (+ C-5 staff side) — everything waiting on a decision, grouped by who it's waiting on,
 * with bulk moves: submit drafts, send to the client (one email for the batch), approve. Each
 * move runs through the normal transition rules; what can't move is reported, not forced.
 */
export default function CampaignApprovals(props: ApprovalsProps) {
    const { campaign, groups, brief } = props;
    const [selected, setSelected] = useState<Set<number>>(new Set());
    const [busy, setBusy] = useState<string | null>(null);
    const posts = groups.flatMap((group) => group.posts);
    const chosen = posts.filter((post) => selected.has(post.id));

    const toggle = (id: number) =>
        setSelected((current) => {
            const next = new Set(current);

            if (next.has(id)) {
                next.delete(id);
            } else {
                next.add(id);
            }

            return next;
        });

    const toggleGroup = (ids: number[], on: boolean) =>
        setSelected((current) => {
            const next = new Set(current);
            ids.forEach((id) => (on ? next.add(id) : next.delete(id)));

            return next;
        });

    const run = async (
        action: 'submit' | 'send_to_client' | 'approve',
        ids: number[],
    ) => {
        setBusy(action);
        const result = await postJson<BulkResult>(bulkPosts.url(campaign.id), {
            action,
            post_ids: ids,
        });
        setBusy(null);

        if (!result.ok) {
            toast.error(result.message);

            return;
        }

        const { done, skipped } = result.data;

        if (done.length > 0) {
            toast.success(
                `${done.length} ${done.length === 1 ? 'post' : 'posts'} moved.${action === 'send_to_client' ? ' The client gets one email for the lot.' : ''}`,
            );
        }

        if (skipped.length > 0) {
            toast.warning(
                `${skipped.length} skipped: ${[...new Set(skipped.map((item) => item.reason))].join(' ')}`,
            );
        }

        setSelected(new Set());
        router.reload();
    };

    const actions = [
        {
            key: 'submit' as const,
            label: 'Submit for review',
            icon: FileCheck2,
            ids: chosen
                .filter((post) => post.can.submit)
                .map((post) => post.id),
        },
        {
            key: 'send_to_client' as const,
            label: 'Send to client',
            icon: Send,
            ids: chosen
                .filter((post) => post.can.sendToClient)
                .map((post) => post.id),
        },
        {
            key: 'approve' as const,
            label: 'Approve',
            icon: CheckCheck,
            ids: chosen
                .filter((post) => post.can.approve)
                .map((post) => post.id),
        },
    ];

    return (
        <CampaignLayout {...props}>
            {brief && brief.status !== 'approved' && (
                <div className="border-info/25 bg-info/5 flex flex-wrap items-center gap-3 rounded-2xl border px-4 py-3 text-sm">
                    <StatusPill
                        kind="brief"
                        value={brief.status}
                        label={brief.status_label}
                    />
                    <span>
                        The brief isn’t signed off yet. Posts can still be
                        reviewed, but the client hasn’t agreed the direction.
                    </span>
                </div>
            )}

            {posts.length === 0 ? (
                <EmptyState
                    icon={CheckCheck}
                    title="All clear."
                    description="Nothing in this campaign is waiting on a decision right now."
                />
            ) : (
                <div className="flex flex-col gap-5">
                    {groups
                        .filter((group) => group.posts.length > 0)
                        .map((group) => {
                            const ids = group.posts.map((post) => post.id);
                            const all = ids.every((id) => selected.has(id));

                            return (
                                <Panel
                                    key={group.status}
                                    eyebrow={`${group.posts.length} ${group.posts.length === 1 ? 'post' : 'posts'}`}
                                    title={group.label}
                                    description={GROUP_COPY[group.status]}
                                    bodyClassName="p-0 sm:p-0"
                                    actions={
                                        <label className="text-muted-foreground flex items-center gap-2 text-xs">
                                            <Checkbox
                                                checked={all}
                                                onCheckedChange={(checked) =>
                                                    toggleGroup(
                                                        ids,
                                                        checked === true,
                                                    )
                                                }
                                                aria-label={`Select all ${group.label}`}
                                            />
                                            Select all
                                        </label>
                                    }
                                >
                                    <ul className="divide-border/70 divide-y">
                                        {group.posts.map((post) => (
                                            <CampaignPostRow
                                                key={post.id}
                                                post={post}
                                                href={editPost(post.id)}
                                                leading={
                                                    <Checkbox
                                                        checked={selected.has(
                                                            post.id,
                                                        )}
                                                        onCheckedChange={() =>
                                                            toggle(post.id)
                                                        }
                                                        aria-label={`Select ${post.master_caption ?? 'post'}`}
                                                    />
                                                }
                                            />
                                        ))}
                                    </ul>
                                </Panel>
                            );
                        })}
                </div>
            )}

            {selected.size > 0 && (
                <div className="bg-ink text-ink-foreground animate-rise sticky bottom-4 z-10 flex flex-wrap items-center justify-between gap-3 self-center rounded-full px-5 py-2.5 shadow-md">
                    <span className="text-sm font-medium">
                        {selected.size} selected
                    </span>
                    <div className="flex flex-wrap gap-2">
                        {actions.map((action) => (
                            <Button
                                key={action.key}
                                size="sm"
                                variant={
                                    action.key === 'approve'
                                        ? 'spark'
                                        : 'on-ink'
                                }
                                disabled={
                                    busy !== null || action.ids.length === 0
                                }
                                onClick={() => run(action.key, action.ids)}
                                title={
                                    action.ids.length === 0
                                        ? 'None of the selected posts can make this move'
                                        : undefined
                                }
                            >
                                {busy === action.key ? (
                                    <Spinner />
                                ) : (
                                    <action.icon />
                                )}
                                {action.label}
                                {action.ids.length > 0 &&
                                action.ids.length !== selected.size
                                    ? ` (${action.ids.length})`
                                    : ''}
                            </Button>
                        ))}
                        <Button
                            size="sm"
                            variant="on-ink"
                            onClick={() => setSelected(new Set())}
                        >
                            <Undo2 />
                            Clear
                        </Button>
                    </div>
                </div>
            )}
        </CampaignLayout>
    );
}

CampaignApprovals.layout = {
    breadcrumbs: [{ title: 'Campaigns', href: campaignsIndex() }],
};
