import { Link } from '@inertiajs/react';
import { Lightbulb, TrendingUp } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { runAiTextJob } from '@/lib/ai-text-job';
import { show as showAiPostIdea } from '@/routes/ai-post-ideas';
import { show as showAiTrend } from '@/routes/ai-trends';
import aiClients from '@/routes/clients/ai';

export type AiTrendData = {
    id: number;
    title: string;
    summary: string | null;
    platforms: string[];
    confidence: number;
    source: string | null;
};

export type AiIdeaData = {
    id: number;
    title: string;
    description: string | null;
    platforms: string[];
    suggestedCaption: string | null;
    post_id: number | null;
};

export type AiInsight =
    | { type: 'trend'; item: AiTrendData }
    | { type: 'idea'; item: AiIdeaData };

/**
 * Ask the AI for fresh trends and ideas (Step 2.4). Step M-7.2: both run as queued jobs we poll, so
 * a slow vendor never blocks the panel; each saves its results server-side, and the caller reloads
 * the deferred `trends`/`ideas` props once this resolves. Throws with the server's message on
 * failure (budget, provider error) so the panel can show it.
 */
export async function requestAiInsights(
    clientId: number,
    platforms: string[],
): Promise<void> {
    const [trends, ideas] = await Promise.all([
        runAiTextJob(aiClients.trends.url(clientId), { platforms }),
        runAiTextJob(aiClients.ideas.url(clientId), {}),
    ]);

    if (!trends.ok && !trends.cancelled) {
        throw new Error(trends.message);
    }

    if (!ideas.ok && !ideas.cancelled) {
        throw new Error(ideas.message);
    }
}

/** One trend or idea in the combined list, linking to its detail page. */
export function AiInsightCard({ insight }: { insight: AiInsight }) {
    const { type, item } = insight;
    const isTrend = type === 'trend';

    return (
        <Link
            href={isTrend ? showAiTrend(item.id) : showAiPostIdea(item.id)}
            className="border-border bg-card hover:border-primary/30 hover:bg-muted/40 flex flex-col gap-1 rounded-xl border p-3 shadow-sm transition-colors"
        >
            <div className="flex items-center justify-between gap-2">
                <div className="font-medium">{item.title}</div>
                <div className="flex items-center gap-2">
                    {!isTrend && insight.item.post_id && (
                        <Badge variant="success">Draft created</Badge>
                    )}
                    {isTrend && insight.item.source && (
                        <Badge
                            variant={
                                insight.item.source === 'AI hypothesis'
                                    ? 'outline'
                                    : 'success'
                            }
                        >
                            {insight.item.source}
                        </Badge>
                    )}
                    <Badge
                        variant="outline"
                        className="inline-flex items-center gap-1"
                    >
                        {isTrend ? (
                            <TrendingUp className="size-3" />
                        ) : (
                            <Lightbulb className="size-3" />
                        )}
                        {isTrend ? 'Trend' : 'Idea'}
                    </Badge>
                </div>
            </div>
            <p className="text-muted-foreground line-clamp-2 text-sm">
                {isTrend ? insight.item.summary : insight.item.description}
            </p>
            {item.platforms.length > 0 && (
                <div className="mt-1 flex flex-wrap gap-1">
                    {item.platforms.map((platform) => (
                        <Badge key={platform} variant="outline">
                            {platform}
                        </Badge>
                    ))}
                </div>
            )}
        </Link>
    );
}
