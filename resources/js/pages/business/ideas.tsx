import { Deferred, Head, router } from '@inertiajs/react';
import {
    ArrowRight,
    CalendarRange,
    Check,
    Lightbulb,
    Loader2,
    Sparkles,
    TrendingUp,
    X,
} from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { EmptyState, Page, PageHeader, Panel } from '@/components/page';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { http } from '@/lib/http';
import { persist as persistIdea } from '@/routes/ai-post-ideas';
import {
    generate as generateIdeas,
    index as ideasIndex,
} from '@/routes/business/ideas';
import {
    accept as acceptPlan,
    discard as discardPlan,
    generate as generatePlan,
} from '@/routes/business/plan';

type Idea = {
    id: number;
    title: string;
    description: string | null;
    platforms: string[];
    suggestedCaption: string | null;
    post_id: number | null;
};

type Trend = {
    id: number;
    title: string;
    summary: string | null;
    platforms: string[];
    confidence: number | null;
};

type PlanItem = {
    id: number;
    title: string;
    description: string | null;
    platforms: string[];
    suggestedCaption: string | null;
    slotLabel: string | null;
    plannedFor: string | null;
};

type Props = {
    aiEnabled: boolean;
    hasGoal: boolean;
    pillars: string[];
    ideas?: Idea[];
    trends?: Trend[];
    plan?: PlanItem[];
};

export default function BusinessIdeas({
    aiEnabled,
    hasGoal,
    ideas,
    trends,
    plan,
}: Props) {
    const [generating, setGenerating] = useState(false);
    const [planning, setPlanning] = useState(false);

    const generate = async () => {
        setGenerating(true);

        const response = await http.post(
            generateIdeas().url,
            {},
            {
                toastOnError: true,
                rateLimitMessage:
                    'Give it a moment before asking for more ideas.',
            },
        );

        setGenerating(false);

        if (response.ok) {
            toast.success('Fresh ideas are in.');
            router.reload({ only: ['ideas'] });
        }
    };

    const planWeek = async () => {
        setPlanning(true);

        const response = await http.post(
            generatePlan().url,
            {},
            {
                toastOnError: true,
                rateLimitMessage: 'Give it a moment before planning again.',
            },
        );

        setPlanning(false);

        if (response.ok) {
            toast.success('Your week is planned.');
            router.reload({ only: ['plan'] });
        }
    };

    return (
        <Page>
            <Head title="Ideas" />

            <PageHeader
                eyebrow="For your brand"
                title={
                    <>
                        Fresh <em>ideas</em>.
                    </>
                }
                description="Post ideas and trends tuned to your brand — turn any into a draft in one tap."
                actions={
                    aiEnabled && (
                        <>
                            {hasGoal && (
                                <Button
                                    variant="outline"
                                    disabled={planning}
                                    onClick={() => void planWeek()}
                                >
                                    {planning ? (
                                        <Loader2 className="animate-spin" />
                                    ) : (
                                        <CalendarRange />
                                    )}
                                    Plan my week
                                </Button>
                            )}
                            <Button
                                variant="brand"
                                disabled={generating}
                                onClick={() => void generate()}
                            >
                                {generating ? (
                                    <Loader2 className="animate-spin" />
                                ) : (
                                    <Sparkles />
                                )}
                                More ideas
                            </Button>
                        </>
                    )
                }
            />

            {aiEnabled && hasGoal && (
                <Panel
                    icon={CalendarRange}
                    eyebrow="Your week"
                    title="This week's plan"
                    description="Draft posts for the days you mean to post on. Add the ones you like."
                >
                    <Deferred data="plan" fallback={<ListSkeleton />}>
                        <WeeklyPlan plan={plan ?? []} />
                    </Deferred>
                </Panel>
            )}

            <Panel icon={Lightbulb} eyebrow="Ready to use" title="Post ideas">
                <Deferred data="ideas" fallback={<ListSkeleton />}>
                    <IdeaList ideas={ideas ?? []} aiEnabled={aiEnabled} />
                </Deferred>
            </Panel>

            <Panel icon={TrendingUp} eyebrow="Worth a look" title="Trends">
                <Deferred data="trends" fallback={<ListSkeleton />}>
                    <TrendList trends={trends ?? []} aiEnabled={aiEnabled} />
                </Deferred>
            </Panel>
        </Page>
    );
}

function IdeaList({ ideas, aiEnabled }: { ideas: Idea[]; aiEnabled: boolean }) {
    const [using, setUsing] = useState<number | null>(null);

    if (ideas.length === 0) {
        return (
            <EmptyState
                compact
                icon={Lightbulb}
                title="No ideas yet."
                description={
                    aiEnabled
                        ? 'Tap “More ideas” and we’ll suggest a few for your brand.'
                        : 'Idea suggestions turn on with your plan — check back soon.'
                }
            />
        );
    }

    const use = (idea: Idea) => {
        setUsing(idea.id);
        router.post(
            persistIdea(idea.id).url,
            {},
            { onFinish: () => setUsing(null) },
        );
    };

    return (
        <ul className="flex flex-col gap-3">
            {ideas.map((idea) => (
                <li
                    key={idea.id}
                    className="border-border/70 bg-background/40 flex flex-col gap-2 rounded-xl border p-4"
                >
                    <div className="flex items-start justify-between gap-4">
                        <span className="font-medium">{idea.title}</span>
                        <Button
                            size="sm"
                            variant="outline"
                            disabled={
                                using === idea.id || idea.post_id !== null
                            }
                            onClick={() => use(idea)}
                        >
                            {idea.post_id !== null ? (
                                'Added'
                            ) : (
                                <>
                                    Use this
                                    <ArrowRight />
                                </>
                            )}
                        </Button>
                    </div>
                    {idea.description && (
                        <p className="text-muted-foreground text-sm">
                            {idea.description}
                        </p>
                    )}
                </li>
            ))}
        </ul>
    );
}

function TrendList({
    trends,
    aiEnabled,
}: {
    trends: Trend[];
    aiEnabled: boolean;
}) {
    if (trends.length === 0) {
        return (
            <EmptyState
                compact
                icon={TrendingUp}
                title="No trends yet."
                description={
                    aiEnabled
                        ? 'Trends for your platforms will show up here as we find them.'
                        : 'Trend discovery turns on with your plan.'
                }
            />
        );
    }

    return (
        <ul className="flex flex-col gap-3">
            {trends.map((trend) => (
                <li
                    key={trend.id}
                    className="border-border/70 bg-background/40 flex flex-col gap-1.5 rounded-xl border p-4"
                >
                    <span className="font-medium">{trend.title}</span>
                    {trend.summary && (
                        <p className="text-muted-foreground text-sm">
                            {trend.summary}
                        </p>
                    )}
                </li>
            ))}
        </ul>
    );
}

function WeeklyPlan({ plan }: { plan: PlanItem[] }) {
    const [busy, setBusy] = useState(false);

    if (plan.length === 0) {
        return (
            <EmptyState
                compact
                icon={CalendarRange}
                title="Nothing planned yet."
                description="Tap “Plan my week” and we’ll draft a post for each open day on your goal."
            />
        );
    }

    const act = async (route: string, ids: number[], done: string) => {
        if (ids.length === 0) {
            return;
        }

        setBusy(true);
        const response = await http.post(
            route,
            { ids },
            { toastOnError: true },
        );
        setBusy(false);

        if (response.ok) {
            toast.success(done);
            router.reload({ only: ['plan', 'ideas'] });
        }
    };

    const allIds = plan.map((item) => item.id);

    return (
        <div className="flex flex-col gap-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <span className="text-muted-foreground text-sm">
                    {plan.length} draft{plan.length === 1 ? '' : 's'} ready to
                    add.
                </span>
                <div className="flex gap-2">
                    <Button
                        size="sm"
                        variant="brand"
                        disabled={busy}
                        onClick={() =>
                            void act(
                                acceptPlan().url,
                                allIds,
                                'Added to your drafts.',
                            )
                        }
                    >
                        <Check />
                        Add all
                    </Button>
                    <Button
                        size="sm"
                        variant="ghost"
                        disabled={busy}
                        onClick={() =>
                            void act(discardPlan().url, allIds, 'Plan cleared.')
                        }
                    >
                        Clear
                    </Button>
                </div>
            </div>

            <ul className="flex flex-col gap-3">
                {plan.map((item) => (
                    <li
                        key={item.id}
                        className="border-border/70 bg-background/40 flex flex-col gap-2 rounded-xl border p-4"
                    >
                        <div className="flex items-start justify-between gap-4">
                            <div className="flex min-w-0 flex-col gap-1">
                                <span className="font-medium">
                                    {item.title}
                                </span>
                                {item.slotLabel && (
                                    <span className="eyebrow text-muted-foreground normal-case">
                                        {item.slotLabel}
                                    </span>
                                )}
                            </div>
                            <div className="flex shrink-0 gap-1.5">
                                <Button
                                    size="sm"
                                    variant="outline"
                                    disabled={busy}
                                    onClick={() =>
                                        void act(
                                            acceptPlan().url,
                                            [item.id],
                                            'Added to your drafts.',
                                        )
                                    }
                                >
                                    Add
                                    <ArrowRight />
                                </Button>
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    aria-label="Discard this plan item"
                                    disabled={busy}
                                    onClick={() =>
                                        void act(
                                            discardPlan().url,
                                            [item.id],
                                            'Removed from your plan.',
                                        )
                                    }
                                >
                                    <X />
                                </Button>
                            </div>
                        </div>
                        {item.description && (
                            <p className="text-muted-foreground text-sm">
                                {item.description}
                            </p>
                        )}
                    </li>
                ))}
            </ul>
        </div>
    );
}

function ListSkeleton() {
    return (
        <div className="flex flex-col gap-3">
            {[0, 1, 2].map((key) => (
                <Skeleton key={key} className="h-20 rounded-xl" />
            ))}
        </div>
    );
}

BusinessIdeas.layout = {
    breadcrumbs: [{ title: 'Ideas', href: ideasIndex() }],
};
