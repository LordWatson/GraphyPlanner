import { Head, Link } from '@inertiajs/react';
import { Inbox } from 'lucide-react';

import { EmptyState, Page, PageHeader, Panel } from '@/components/page';
import type { PortalPostRowData } from '@/components/portal-post-row';
import { PortalPostRow } from '@/components/portal-post-row';
import { resolveStatus, toneBgClass } from '@/lib/status';
import { cn } from '@/lib/utils';
import { index as portalPostsIndex } from '@/routes/portal/posts';

type StatusOption = { value: string; label: string };

/**
 * Step 6.5 — the client portal's posts list: every post visible to this client contact
 * (pre-review internal stages are excluded server-side), filterable by status. Unfiltered, posts
 * waiting on the client lead in their own group so the next action is always first.
 */
export default function PortalPostsIndex({
    posts,
    filters,
    statuses,
}: {
    posts: PortalPostRowData[];
    filters: { status: string | null };
    statuses: StatusOption[];
}) {
    const waiting = filters.status
        ? []
        : posts.filter((post) => post.status === 'waiting_client');
    const rest = filters.status
        ? posts
        : posts.filter((post) => post.status !== 'waiting_client');

    const pill = (active: boolean) =>
        cn(
            'inline-flex shrink-0 items-center gap-1.5 rounded-full border px-3 py-1.5 text-[13px] transition-colors',
            active
                ? 'bg-ink text-ink-foreground dark:bg-foreground dark:text-background border-transparent'
                : 'border-border bg-card text-muted-foreground hover:text-foreground',
        );

    return (
        <>
            <Head title="Posts — Client portal" />
            <Page>
                <PageHeader
                    eyebrow={`Posts — ${posts.length} ${filters.status ? 'matching' : 'in view'}`}
                    title={
                        <>
                            Your <em>posts</em>
                        </>
                    }
                    description="Everything the studio has shared with you — awaiting your review, scheduled, and live."
                />

                <nav
                    aria-label="Filter by status"
                    className="-mx-1 flex [scrollbar-width:none] gap-1.5 overflow-x-auto px-1 pb-1"
                >
                    <Link
                        href={portalPostsIndex()}
                        preserveScroll
                        className={pill(!filters.status)}
                        aria-current={!filters.status ? 'page' : undefined}
                    >
                        All
                    </Link>
                    {statuses.map((status) => (
                        <Link
                            key={status.value}
                            href={portalPostsIndex({
                                query: { status: status.value },
                            })}
                            preserveScroll
                            className={pill(filters.status === status.value)}
                            aria-current={
                                filters.status === status.value
                                    ? 'page'
                                    : undefined
                            }
                        >
                            <span
                                aria-hidden
                                className={cn(
                                    'size-1.5 rounded-full',
                                    toneBgClass[
                                        resolveStatus('post', status.value).tone
                                    ],
                                )}
                            />
                            {status.label}
                        </Link>
                    ))}
                </nav>

                {posts.length === 0 ? (
                    <EmptyState
                        icon={Inbox}
                        title={
                            filters.status
                                ? 'Nothing with this status.'
                                : 'No posts yet.'
                        }
                        description={
                            filters.status
                                ? 'Try another filter, or view everything.'
                                : 'Posts appear here as soon as the studio shares them with you for review.'
                        }
                    />
                ) : (
                    <div className="animate-rise flex flex-col gap-5">
                        {waiting.length > 0 && (
                            <Panel
                                eyebrow={`${waiting.length} waiting`}
                                title="Needs your approval"
                                bodyClassName="p-2"
                                className="border-info/25 ring-info/5 ring-4"
                            >
                                {waiting.map((post) => (
                                    <PortalPostRow key={post.id} post={post} />
                                ))}
                            </Panel>
                        )}
                        {rest.length > 0 && (
                            <Panel
                                eyebrow={
                                    filters.status
                                        ? resolveStatus('post', filters.status)
                                              .label
                                        : 'Everything else'
                                }
                                title={
                                    filters.status
                                        ? `${rest.length} post${rest.length === 1 ? '' : 's'}`
                                        : 'In progress, scheduled & live'
                                }
                                bodyClassName="p-2"
                            >
                                {rest.map((post) => (
                                    <PortalPostRow key={post.id} post={post} />
                                ))}
                            </Panel>
                        )}
                    </div>
                )}
            </Page>
        </>
    );
}
