import { Head, Link, router } from '@inertiajs/react';
import {
    ArrowLeft,
    ArrowUpRight,
    Megaphone,
    Pencil,
    Trash2,
} from 'lucide-react';
import { useState } from 'react';
import { confirmDialog } from '@/components/confirm-dialog';
import { EmptyState, PageHeader, StatStrip } from '@/components/page';
import { platformIcon } from '@/components/platform-icon';
import { StatusPill } from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { formatDateRange } from '@/lib/dates';
import {
    destroy as destroyCampaign,
    edit as editCampaign,
} from '@/routes/campaigns';
import { index as indexClients, show as showClient } from '@/routes/clients';
import { edit as editPost } from '@/routes/posts';

type CampaignData = {
    id: number;
    name: string;
    status: string;
    status_label: string;
    start_date: string | null;
    end_date: string | null;
    goal: string | null;
    notes: string | null;
};

type PostRow = {
    id: number;
    status: string;
    status_label: string;
    master_caption: string | null;
    targets: {
        platform: string | null;
        handle: string | null;
        scheduled_local_date: string | null;
    }[];
    created_at: string | null;
};

const IN_REVIEW = ['internal_review', 'waiting_client', 'changes_requested'];
const DAY_MS = 24 * 60 * 60 * 1000;

const shortDate = new Intl.DateTimeFormat('en-GB', {
    day: 'numeric',
    month: 'short',
});

function parseDate(date: string | null): Date | null {
    if (!date) {
        return null;
    }

    const [y, m, d] = date.split('-').map(Number);

    return new Date(y, m - 1, d);
}

/** A ruled "spec" row, book §1.2 "Purpose" style: mono label left, statement right. */
function SpecRow({
    label,
    children,
}: {
    label: string;
    children: React.ReactNode;
}) {
    return (
        <div className="border-border grid grid-cols-1 gap-2 border-t py-5 sm:grid-cols-[180px_minmax(0,1fr)] sm:gap-8">
            <dt className="eyebrow text-muted-foreground pt-1">{label}</dt>
            <dd>{children}</dd>
        </div>
    );
}

export default function CampaignShow({
    campaign,
    client,
    posts,
    can,
}: {
    campaign: CampaignData;
    client: { id: number; name: string };
    posts: PostRow[];
    can: { update: boolean; delete: boolean };
}) {
    const [deleting, setDeleting] = useState(false);
    const [today] = useState(() => {
        const now = new Date();

        return new Date(now.getFullYear(), now.getMonth(), now.getDate());
    });

    const start = parseDate(campaign.start_date);
    const end = parseDate(campaign.end_date);
    const published = posts.filter((post) => post.status === 'published');
    const inReview = posts.filter((post) => IN_REVIEW.includes(post.status));

    // Days left only means something while the campaign is running; before it starts we count
    // down to the start instead.
    const timing = (() => {
        if (start && start > today) {
            return {
                label: 'Starts in',
                value: Math.round((start.getTime() - today.getTime()) / DAY_MS),
                caption: 'Days to launch',
            };
        }

        if (end && end >= today) {
            return {
                label: 'Days left',
                value: Math.round((end.getTime() - today.getTime()) / DAY_MS),
                caption: `Wraps ${shortDate.format(end)}`,
            };
        }

        return {
            label: 'Ran for',
            value:
                start && end
                    ? Math.round((end.getTime() - start.getTime()) / DAY_MS) + 1
                    : '—',
            caption: end ? `Ended ${shortDate.format(end)}` : 'No end date',
        };
    })();

    const handleDelete = async () => {
        if (
            !(await confirmDialog({
                title: `Delete ${campaign.name}?`,
                description:
                    'The campaign is removed for good. Its posts stay on the client, just no longer grouped under it.',
                confirmLabel: 'Delete campaign',
                tone: 'destructive',
            }))
        ) {
            return;
        }

        router.delete(destroyCampaign.url(campaign.id), {
            onStart: () => setDeleting(true),
            onFinish: () => setDeleting(false),
        });
    };

    return (
        <>
            <Head title={`${campaign.name} — ${client.name}`} />
            <div className="mx-auto flex w-full max-w-5xl flex-col gap-8 px-4 pt-6 pb-12 md:px-10 md:pt-10">
                <Link
                    href={showClient(client.id)}
                    className="text-muted-foreground hover:text-foreground -mb-2 inline-flex items-center gap-1.5 self-start text-sm"
                >
                    <ArrowLeft className="size-4" />
                    {client.name}
                </Link>

                <PageHeader
                    eyebrow={`Campaign — ${client.name}`}
                    title={campaign.name}
                    meta={
                        <>
                            <StatusPill
                                kind="campaign"
                                value={campaign.status}
                                label={campaign.status_label}
                            />
                            <span className="eyebrow text-muted-foreground">
                                {formatDateRange(
                                    campaign.start_date,
                                    campaign.end_date,
                                )}
                            </span>
                        </>
                    }
                    actions={
                        <>
                            {can.update && (
                                <Button asChild variant="outline">
                                    <Link href={editCampaign(campaign.id)}>
                                        <Pencil />
                                        Edit
                                    </Link>
                                </Button>
                            )}
                            {can.delete && (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    className="text-destructive hover:bg-destructive/8 hover:text-destructive"
                                    onClick={handleDelete}
                                    disabled={deleting}
                                >
                                    <Trash2 />
                                    Delete
                                </Button>
                            )}
                        </>
                    }
                />

                <StatStrip
                    className="animate-rise"
                    items={[
                        {
                            label: 'Posts',
                            value: String(posts.length).padStart(2, '0'),
                            caption: 'In this campaign',
                        },
                        {
                            label: 'Published',
                            value: String(published.length).padStart(2, '0'),
                            caption: 'Live so far',
                        },
                        {
                            label: 'In review',
                            value: String(inReview.length).padStart(2, '0'),
                            caption: 'Studio or client',
                        },
                        {
                            label: timing.label,
                            value: timing.value,
                            caption: timing.caption,
                        },
                    ]}
                />

                <dl className="animate-rise border-foreground/85 [&>div:first-child]:border-foreground/85 flex flex-col border-b">
                    <SpecRow label="A — Goal">
                        {campaign.goal ? (
                            <p className="font-display max-w-2xl text-[1.6rem] leading-[1.2] text-pretty whitespace-pre-line">
                                {campaign.goal}
                            </p>
                        ) : (
                            <p className="text-muted-foreground text-sm">
                                No goal set yet.{' '}
                                {can.update &&
                                    'Add one so every post can point at it.'}
                            </p>
                        )}
                    </SpecRow>
                    <SpecRow label="B — Notes">
                        <p className="text-muted-foreground max-w-2xl text-[15px] leading-relaxed whitespace-pre-line">
                            {campaign.notes || 'Nothing noted.'}
                        </p>
                    </SpecRow>
                </dl>

                <section
                    aria-labelledby="campaign-posts"
                    className="flex flex-col gap-4"
                >
                    <div className="flex items-baseline justify-between gap-4">
                        <h2
                            id="campaign-posts"
                            className="text-[17px] font-semibold tracking-[-0.01em]"
                        >
                            The posts
                        </h2>
                        <span className="eyebrow text-muted-foreground">
                            {posts.length} attached
                        </span>
                    </div>

                    {posts.length === 0 ? (
                        <EmptyState
                            compact
                            icon={Megaphone}
                            title="Nothing in it yet."
                            description="Attach posts to this campaign from the post editor and they'll line up here."
                        />
                    ) : (
                        <ul className="border-border/80 bg-card divide-border/70 flex flex-col divide-y overflow-hidden rounded-2xl border shadow-sm">
                            {posts.map((post, index) => {
                                const firstDate = parseDate(
                                    post.targets.find(
                                        (target) => target.scheduled_local_date,
                                    )?.scheduled_local_date ?? null,
                                );

                                return (
                                    <li key={post.id}>
                                        <Link
                                            href={editPost(post.id)}
                                            className="group hover:bg-muted/50 flex items-center gap-4 px-5 py-4 transition-colors"
                                        >
                                            <span className="text-muted-foreground w-6 shrink-0 font-mono text-[11px] tabular-nums">
                                                {String(index + 1).padStart(
                                                    2,
                                                    '0',
                                                )}
                                            </span>
                                            <span className="flex min-w-0 flex-1 flex-col gap-1">
                                                <span className="group-hover:text-primary truncate text-sm font-medium">
                                                    {post.master_caption ||
                                                        'Untitled post'}
                                                </span>
                                                <span className="text-muted-foreground flex min-w-0 flex-wrap items-center gap-x-3 gap-y-1 text-xs">
                                                    {post.targets.length === 0
                                                        ? 'No channels yet'
                                                        : post.targets.map(
                                                              (target, i) => {
                                                                  const Icon =
                                                                      platformIcon(
                                                                          target.platform,
                                                                      );

                                                                  return (
                                                                      <span
                                                                          key={
                                                                              i
                                                                          }
                                                                          className="inline-flex items-center gap-1"
                                                                      >
                                                                          <Icon className="size-3.5" />
                                                                          {target.handle ??
                                                                              target.platform}
                                                                      </span>
                                                                  );
                                                              },
                                                          )}
                                                </span>
                                            </span>
                                            <span className="text-muted-foreground hidden shrink-0 font-mono text-xs tabular-nums sm:inline">
                                                {firstDate
                                                    ? shortDate.format(
                                                          firstDate,
                                                      )
                                                    : 'Unscheduled'}
                                            </span>
                                            <StatusPill
                                                kind="post"
                                                value={post.status}
                                                label={post.status_label}
                                                className="shrink-0 whitespace-nowrap"
                                            />
                                            <ArrowUpRight className="text-muted-foreground size-4 shrink-0 transition-transform group-hover:translate-x-0.5 group-hover:-translate-y-0.5" />
                                        </Link>
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                </section>
            </div>
        </>
    );
}

CampaignShow.layout = {
    breadcrumbs: [{ title: 'Clients', href: indexClients() }],
};
