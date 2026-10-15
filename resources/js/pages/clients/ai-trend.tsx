import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Sparkles } from 'lucide-react';
import { Page, PageHeader, Panel } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { show as showClient } from '@/routes/clients';

const clientAiTabUrl = (clientId: number) => `${showClient(clientId).url}#ai`;

type TrendData = {
    id: number;
    title: string;
    summary: string | null;
    platforms: string[];
    confidence: number;
    created_at: string | null;
};

export default function AiTrendShow({
    client,
    trend,
}: {
    client: { id: number; name: string };
    trend: TrendData;
}) {
    const confidence = Math.round(
        Math.min(1, Math.max(0, trend.confidence)) * 100,
    );

    return (
        <>
            <Head title={`${trend.title} — Trend`} />
            <Page width="narrow">
                <Link
                    href={clientAiTabUrl(client.id)}
                    className="text-muted-foreground hover:text-foreground inline-flex w-fit items-center gap-1.5 text-sm"
                >
                    <ArrowLeft className="size-4" />
                    Back to {client.name}
                </Link>

                <PageHeader
                    eyebrow={`AI trend — ${client.name}${trend.created_at ? ` · ${trend.created_at}` : ''}`}
                    title={trend.title}
                    meta={trend.platforms.map((platform, i) => (
                        <Badge key={i} variant="outline" className="capitalize">
                            {platform}
                        </Badge>
                    ))}
                />

                <div className="grid grid-cols-1 gap-5 md:grid-cols-[1fr_15rem]">
                    <Panel
                        eyebrow="Summary"
                        icon={Sparkles}
                        title="What's happening"
                    >
                        <p className="text-[15px] leading-relaxed whitespace-pre-wrap">
                            {trend.summary || (
                                <span className="text-muted-foreground">
                                    No summary provided.
                                </span>
                            )}
                        </p>
                    </Panel>
                    <div className="bg-ink text-ink-foreground flex flex-col justify-between gap-6 rounded-2xl p-5">
                        <span className="eyebrow opacity-70">Confidence</span>
                        <div className="flex flex-col gap-3">
                            <span className="font-serif text-6xl leading-none tabular-nums">
                                {confidence}
                                <span className="text-primary text-3xl">%</span>
                            </span>
                            <div
                                className="bg-ink-foreground/15 h-1.5 w-full overflow-hidden rounded-full"
                                role="meter"
                                aria-valuenow={confidence}
                                aria-valuemin={0}
                                aria-valuemax={100}
                                aria-label="Confidence"
                            >
                                <div
                                    className="bg-primary h-full rounded-full"
                                    style={{ width: `${confidence}%` }}
                                />
                            </div>
                        </div>
                    </div>
                </div>
            </Page>
        </>
    );
}
