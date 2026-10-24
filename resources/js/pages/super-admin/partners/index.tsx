import { Head, Link, router } from '@inertiajs/react';
import type { ColumnDef } from '@tanstack/react-table';
import {
    ArrowRight,
    CheckCircle2,
    HeartHandshake,
    MessageSquareHeart,
    Users,
} from 'lucide-react';
import { DataTable } from '@/components/data-table';
import {
    ChapterHero,
    EmptyState,
    HeroStat,
    Page,
    Panel,
    StatStrip,
} from '@/components/page';
import { StatusPill } from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { formatDateTime } from '@/lib/dates';
import { index as feedbackIndex } from '@/routes/super-admin/feedback';
import { show as showOrganization } from '@/routes/super-admin/organizations';
import { index as partnersIndex } from '@/routes/super-admin/partners';

type PartnerRow = {
    id: number;
    name: string;
    slug: string;
    created_at: string | null;
    is_suspended: boolean;
    members: number;
    weekly_active_users: number;
    posts_published: number;
    approvals: number;
    client_approvals: number;
    internal_approvals: number;
    invoices_sent: number;
    ai_calls: number;
};

type Usage = {
    range: { range: string; from: string; to: string };
    weekly_active_since: string;
    partners: PartnerRow[];
    totals: {
        partners: number;
        weekly_active_users: number;
        posts_published: number;
        approvals: number;
        client_approvals: number;
        internal_approvals: number;
        invoices_sent: number;
        ai_calls: number;
    };
};

type FeedbackRow = {
    id: number;
    message: string;
    page: string | null;
    created_at: string | null;
    organization: { id: number; name: string; slug: string } | null;
    user: { id: number; name: string; email: string } | null;
};

type Props = {
    usage: Usage;
    recentFeedback: FeedbackRow[];
    ranges: { value: string; label: string }[];
};

const columns: ColumnDef<PartnerRow, unknown>[] = [
    {
        id: 'name',
        header: 'Partner',
        enableHiding: false,
        cell: ({ row }) => (
            <span className="flex min-w-0 items-center gap-2.5">
                <Link
                    href={showOrganization(row.original.id)}
                    className="truncate font-medium hover:underline"
                >
                    {row.original.name}
                </Link>
                {row.original.is_suspended && (
                    <StatusPill
                        kind="organization"
                        value="suspended"
                        className="shrink-0"
                    />
                )}
            </span>
        ),
    },
    {
        id: 'weekly_active_users',
        header: 'Active (7d)',
        meta: {
            label: 'Weekly active users',
            align: 'right',
            className: 'font-mono tabular-nums',
        },
        cell: ({ row }) =>
            `${row.original.weekly_active_users} / ${row.original.members}`,
    },
    {
        id: 'posts_published',
        header: 'Published',
        meta: {
            label: 'Posts published',
            hideBelow: 'sm',
            align: 'right',
            className: 'font-mono tabular-nums',
        },
        cell: ({ row }) => row.original.posts_published,
    },
    {
        id: 'client_approvals',
        header: 'Client appr.',
        meta: {
            label: 'Client approvals',
            hideBelow: 'md',
            align: 'right',
            className: 'font-mono tabular-nums',
        },
        cell: ({ row }) => row.original.client_approvals,
    },
    {
        id: 'internal_approvals',
        header: 'Internal appr.',
        meta: {
            label: 'Internal approvals',
            hideBelow: 'lg',
            align: 'right',
            className: 'font-mono tabular-nums',
        },
        cell: ({ row }) => row.original.internal_approvals,
    },
    {
        id: 'invoices_sent',
        header: 'Invoices',
        meta: {
            label: 'Invoices sent',
            hideBelow: 'md',
            align: 'right',
            className: 'font-mono tabular-nums',
        },
        cell: ({ row }) => row.original.invoices_sent,
    },
    {
        id: 'ai_calls',
        header: 'AI calls',
        meta: {
            label: 'AI calls',
            hideBelow: 'lg',
            align: 'right',
            className: 'font-mono tabular-nums',
        },
        cell: ({ row }) => row.original.ai_calls,
    },
];

/**
 * Step M-8.2 — the design-partner usage view: are the (up to five) partners really using Alima?
 * One row per partner with weekly active users, posts published, approvals, invoices sent and AI
 * calls over the selected range (weekly-active is always a 7-day window). Plus the latest in-app
 * feedback, with a link to the full inbox.
 */
export default function PartnersIndex({
    usage,
    recentFeedback,
    ranges,
}: Props) {
    const { totals, partners, range } = usage;

    const onRangeChange = (value: string) => {
        router.get(
            partnersIndex.url({
                query: value === '30d' ? {} : { range: value },
            }),
            undefined,
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    return (
        <>
            <Head title="Partners" />
            <Page>
                <ChapterHero
                    eyebrow="Design-partner release"
                    title={
                        <>
                            Our <em>partners</em>, up close.
                        </>
                    }
                    description="The agencies on the design-partner offer, and the numbers that say whether Alima has landed for them. Weekly active users are the last 7 days; the rest follow the range."
                    actions={
                        <Button variant="on-ink" size="lg" asChild>
                            <Link href={feedbackIndex()}>
                                <MessageSquareHeart />
                                Feedback inbox
                            </Link>
                        </Button>
                    }
                    aside={
                        <HeroStat
                            icon={Users}
                            eyebrow="Active this week"
                            value={String(totals.weekly_active_users).padStart(
                                2,
                                '0',
                            )}
                            caption={`across ${totals.partners} ${totals.partners === 1 ? 'partner' : 'partners'}`}
                        />
                    }
                />

                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <StatStrip
                        className="animate-rise flex-1"
                        items={[
                            {
                                label: 'Published',
                                value: String(totals.posts_published).padStart(
                                    2,
                                    '0',
                                ),
                                caption: 'In range',
                            },
                            {
                                label: 'Approvals',
                                value: String(totals.approvals).padStart(
                                    2,
                                    '0',
                                ),
                                caption: `${totals.client_approvals} client · ${totals.internal_approvals} internal`,
                            },
                            {
                                label: 'Invoices',
                                value: String(totals.invoices_sent).padStart(
                                    2,
                                    '0',
                                ),
                                caption: 'Sent to clients',
                            },
                            {
                                label: 'AI calls',
                                value: String(totals.ai_calls).padStart(2, '0'),
                                caption: 'Drafts & images',
                            },
                        ]}
                    />
                    <Select value={range.range} onValueChange={onRangeChange}>
                        <SelectTrigger
                            className="w-full sm:w-44"
                            aria-label="Usage range"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {ranges.map((option) => (
                                <SelectItem
                                    key={option.value}
                                    value={option.value}
                                >
                                    {option.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>

                {partners.length === 0 ? (
                    <EmptyState
                        icon={HeartHandshake}
                        title="No design partners yet."
                        description="Open an organization and switch on “Design partner” to track its usage here."
                    />
                ) : (
                    <DataTable
                        caption="Design partners"
                        columns={columns}
                        data={partners}
                        getRowId={(partner) => String(partner.id)}
                        rowHref={(partner) => showOrganization.url(partner.id)}
                        storageKey="super-admin-partners"
                    />
                )}

                <Panel
                    eyebrow="Straight from the team"
                    title="Recent feedback"
                    icon={MessageSquareHeart}
                    actions={
                        <Button variant="ghost" size="sm" asChild>
                            <Link href={feedbackIndex()}>
                                All feedback
                                <ArrowRight />
                            </Link>
                        </Button>
                    }
                    bodyClassName={
                        recentFeedback.length === 0 ? undefined : 'p-0 sm:p-0'
                    }
                >
                    {recentFeedback.length === 0 ? (
                        <EmptyState
                            compact
                            icon={CheckCircle2}
                            title="A quiet inbox."
                            description="No product feedback has come in yet. It lands here the moment a partner sends it."
                        />
                    ) : (
                        <ul className="divide-border/70 divide-y">
                            {recentFeedback.map((item) => (
                                <li
                                    key={item.id}
                                    className="flex flex-col gap-1.5 px-5 py-4 sm:px-6"
                                >
                                    <p className="text-[15px] leading-relaxed text-pretty">
                                        {item.message}
                                    </p>
                                    <p className="text-muted-foreground flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs">
                                        <span className="text-foreground font-medium">
                                            {item.organization?.name ??
                                                'Unknown workspace'}
                                        </span>
                                        {item.user && (
                                            <>
                                                <span aria-hidden>·</span>
                                                <span>{item.user.name}</span>
                                            </>
                                        )}
                                        {item.page && (
                                            <>
                                                <span aria-hidden>·</span>
                                                <span className="font-mono">
                                                    {item.page}
                                                </span>
                                            </>
                                        )}
                                        <span aria-hidden>·</span>
                                        <span className="font-mono">
                                            {formatDateTime(item.created_at)}
                                        </span>
                                    </p>
                                </li>
                            ))}
                        </ul>
                    )}
                </Panel>
            </Page>
        </>
    );
}

PartnersIndex.layout = {
    breadcrumbs: [{ title: 'Partners', href: partnersIndex() }],
};
