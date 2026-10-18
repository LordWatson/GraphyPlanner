import { Head, Link } from '@inertiajs/react';
import {
    ArrowLeft,
    ArrowRight,
    Download,
    Inbox,
    MessagesSquare,
    Trophy,
} from 'lucide-react';
import { CampaignConversation } from '@/components/campaigns/campaign-conversation';
import { campaignSwatch } from '@/components/campaigns/campaign-chip';
import { CampaignPostRow } from '@/components/campaigns/campaign-post-row';
import { PortalBriefSection } from '@/components/campaigns/portal-brief';
import type { PortalBrief } from '@/components/campaigns/portal-brief';
import { formatKpi } from '@/components/campaigns/results/kpi-rows';
import type { KpiResult } from '@/components/campaigns/results/kpi-rows';
import type {
    CampaignComment,
    CampaignTimeline,
} from '@/components/campaigns/types';
import { EmptyState, Page, PageHeader, Panel } from '@/components/page';
import { StatusPill } from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { formatDate, formatDateRange } from '@/lib/dates';
import { cn } from '@/lib/utils';
import {
    index as portalCampaignsIndex,
    review as reviewCampaign,
} from '@/routes/portal/campaigns';
import { store as storeComment } from '@/routes/portal/campaigns/comments';
import { pdf as reportPdf } from '@/routes/portal/campaigns/report';
import { show as portalPost } from '@/routes/portal/posts';

type PortalPost = {
    id: number;
    status: string;
    status_label: string;
    master_caption: string | null;
    targets: { platform: string | null; handle: string | null }[];
    scheduled_local_date: string | null;
    scheduled_local_time: string | null;
    phase_id: number | null;
    needs_review: boolean;
};

type Props = {
    campaign: {
        id: number;
        name: string;
        code: string | null;
        colour: string | null;
        status: string;
        status_label: string;
        start_date: string | null;
        end_date: string | null;
        goal: string | null;
        timeline: CampaignTimeline;
    };
    brief: PortalBrief | null;
    phases: {
        id: number;
        name: string;
        starts_on: string | null;
        ends_on: string | null;
        colour: string | null;
    }[];
    posts: PortalPost[];
    needsReview: number;
    comments: CampaignComment[];
    report: {
        summary: string | null;
        highlights: string[];
        learnings: string | null;
        next_steps: string | null;
        kpi_snapshot: KpiResult[];
        shared_at: string | null;
    } | null;
};

/**
 * Step C-5.1 — the client's view of one campaign, as one story: when it runs, the brief to sign
 * off, the phases, what's going out (only posts from "Waiting on you" onward), the conversation
 * with the studio, and — once shared — the wrap report. No internal states, notes, health,
 * readiness or money, ever (the server's allowlist guarantees it). Mobile first.
 */
export default function PortalCampaignShow({
    campaign,
    brief,
    phases,
    posts,
    needsReview,
    comments,
    report,
}: Props) {
    const groups = [
        ...phases.map((phase) => ({
            ...phase,
            posts: posts.filter((post) => post.phase_id === phase.id),
        })),
        {
            id: 0,
            name: phases.length > 0 ? 'Also in this campaign' : 'The posts',
            starts_on: null,
            ends_on: null,
            colour: null,
            posts: posts.filter(
                (post) =>
                    post.phase_id === null ||
                    !phases.some((phase) => phase.id === post.phase_id),
            ),
        },
    ].filter((group) => group.posts.length > 0);

    return (
        <>
            <Head title={`${campaign.name} — Client portal`} />
            <Page>
                <Link
                    href={portalCampaignsIndex()}
                    className="text-muted-foreground hover:text-foreground inline-flex w-fit items-center gap-1.5 text-sm"
                >
                    <ArrowLeft className="size-4" />
                    All campaigns
                </Link>

                <PageHeader
                    eyebrow={`Campaign — ${formatDateRange(campaign.start_date, campaign.end_date)}`}
                    title={campaign.name}
                    description={campaign.goal ?? undefined}
                    meta={
                        <>
                            <StatusPill
                                kind="campaign"
                                value={campaign.status}
                                label={campaign.status_label}
                            />
                            <span className="text-[13px] font-medium">
                                {campaign.timeline.sentence}
                            </span>
                        </>
                    }
                    actions={
                        needsReview > 0 && (
                            <Button asChild size="lg">
                                <Link href={reviewCampaign(campaign.id)}>
                                    Review {needsReview}{' '}
                                    {needsReview === 1 ? 'post' : 'posts'}
                                    <ArrowRight />
                                </Link>
                            </Button>
                        )
                    }
                />

                {phases.some((phase) => phase.starts_on) && (
                    <section
                        aria-label="Phases"
                        className="flex flex-col gap-2"
                    >
                        <span className="eyebrow text-muted-foreground">
                            How it unfolds
                        </span>
                        <ol className="flex flex-col gap-1.5 sm:flex-row">
                            {phases.map((phase) => (
                                <li
                                    key={phase.id}
                                    className="border-border bg-card relative flex flex-1 flex-col gap-0.5 overflow-hidden rounded-xl border p-3 pt-4"
                                >
                                    <span
                                        aria-hidden
                                        className={cn(
                                            'absolute inset-x-0 top-0 h-1.5',
                                            campaignSwatch(phase.colour),
                                        )}
                                    />
                                    <span className="text-sm font-semibold">
                                        {phase.name}
                                    </span>
                                    <span className="text-muted-foreground font-mono text-[11px]">
                                        {phase.starts_on
                                            ? formatDateRange(
                                                  phase.starts_on,
                                                  phase.ends_on,
                                              )
                                            : 'Dates to come'}
                                    </span>
                                </li>
                            ))}
                        </ol>
                    </section>
                )}

                {brief && (
                    <PortalBriefSection
                        campaignId={campaign.id}
                        brief={brief}
                    />
                )}

                {report && (
                    <Panel
                        id="results"
                        icon={Trophy}
                        eyebrow={`Wrap report · ${formatDate(report.shared_at)}`}
                        title="How it went"
                        actions={
                            <Button asChild variant="outline" size="sm">
                                <a
                                    href={reportPdf.url(campaign.id)}
                                    target="_blank"
                                    rel="noreferrer"
                                >
                                    <Download />
                                    PDF
                                </a>
                            </Button>
                        }
                    >
                        <div className="flex flex-col gap-5">
                            {report.summary && (
                                <p className="text-[15px] leading-relaxed text-pretty">
                                    {report.summary}
                                </p>
                            )}
                            {report.kpi_snapshot.length > 0 && (
                                <ul className="grid gap-3 sm:grid-cols-2">
                                    {report.kpi_snapshot.map((kpi) => (
                                        <li
                                            key={kpi.metric}
                                            className="border-border flex flex-col gap-1 rounded-xl border p-3"
                                        >
                                            <span className="eyebrow text-muted-foreground">
                                                {kpi.label}
                                            </span>
                                            <span className="font-display text-2xl font-bold tabular-nums">
                                                {kpi.available
                                                    ? formatKpi(
                                                          kpi.unit,
                                                          kpi.actual,
                                                      )
                                                    : '—'}
                                            </span>
                                            <span className="text-muted-foreground text-xs">
                                                Target{' '}
                                                {formatKpi(
                                                    kpi.unit,
                                                    kpi.target,
                                                )}
                                                {kpi.available
                                                    ? kpi.met
                                                        ? ' · Hit'
                                                        : ' · Not quite'
                                                    : ' · Not tracked yet'}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            )}
                            {report.highlights.length > 0 && (
                                <ul className="flex flex-col gap-1.5">
                                    {report.highlights.map((line, index) => (
                                        <li
                                            key={index}
                                            className="flex gap-2.5 text-sm"
                                        >
                                            <span
                                                aria-hidden
                                                className="bg-primary mt-2 size-1.5 shrink-0 rounded-full"
                                            />
                                            {line}
                                        </li>
                                    ))}
                                </ul>
                            )}
                            {report.learnings && (
                                <div className="flex flex-col gap-1">
                                    <span className="eyebrow text-muted-foreground">
                                        What we learned
                                    </span>
                                    <p className="text-sm whitespace-pre-line">
                                        {report.learnings}
                                    </p>
                                </div>
                            )}
                            {report.next_steps && (
                                <div className="flex flex-col gap-1">
                                    <span className="eyebrow text-muted-foreground">
                                        What’s next
                                    </span>
                                    <p className="text-sm whitespace-pre-line">
                                        {report.next_steps}
                                    </p>
                                </div>
                            )}
                        </div>
                    </Panel>
                )}

                {groups.length === 0 ? (
                    <EmptyState
                        icon={Inbox}
                        title="No posts here yet."
                        description="Posts for this campaign appear once the studio shares them with you."
                    />
                ) : (
                    groups.map((group) => (
                        <Panel
                            key={group.id}
                            eyebrow={
                                group.starts_on
                                    ? formatDateRange(
                                          group.starts_on,
                                          group.ends_on,
                                      )
                                    : `${group.posts.length} ${group.posts.length === 1 ? 'post' : 'posts'}`
                            }
                            title={group.name}
                            bodyClassName="p-0 sm:p-0"
                        >
                            <ul className="divide-border/70 divide-y">
                                {group.posts.map((post) => (
                                    <CampaignPostRow
                                        key={post.id}
                                        post={post}
                                        href={portalPost(post.id)}
                                    />
                                ))}
                            </ul>
                        </Panel>
                    ))
                )}

                <Panel
                    icon={MessagesSquare}
                    eyebrow="Conversation"
                    title="Talk to the studio"
                >
                    <CampaignConversation
                        comments={comments}
                        form={storeComment.form(campaign.id)}
                        emptyHint="Questions about timing, priorities or the big picture? Ask here."
                    />
                </Panel>
            </Page>
        </>
    );
}
