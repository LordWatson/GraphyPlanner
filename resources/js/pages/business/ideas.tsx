import { Deferred, Head, router } from '@inertiajs/react';
import {
    ArrowRight,
    Lightbulb,
    Loader2,
    Sparkles,
    TrendingUp,
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

type Props = {
    aiEnabled: boolean;
    pillars: string[];
    ideas?: Idea[];
    trends?: Trend[];
};

export default function BusinessIdeas({ aiEnabled, ideas, trends }: Props) {
    const [generating, setGenerating] = useState(false);

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
                    )
                }
            />

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
