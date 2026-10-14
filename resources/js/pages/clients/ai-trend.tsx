import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Sparkles } from 'lucide-react';
import Heading from '@/components/heading';
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

export default function AiTrendShow({ client, trend }: { client: { id: number; name: string }; trend: TrendData }) {
    return (
        <>
            <Head title={`${trend.title} — Trend`} />
            <div className="mx-auto flex w-full max-w-3xl flex-col gap-6 p-4">
                <Link
                    href={clientAiTabUrl(client.id)}
                    className="inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground"
                >
                    <ArrowLeft className="size-4" />
                    Back to {client.name}
                </Link>

                <div className="grid gap-4 rounded-lg border border-border bg-card p-6">
                    <div className="flex items-start justify-between gap-3">
                        <Heading
                            title={trend.title}
                            description={`AI-discovered trend for ${client.name}${trend.created_at ? ` · discovered ${trend.created_at}` : ''}`}
                        />
                        <span className="flex size-9 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary">
                            <Sparkles className="size-4" />
                        </span>
                    </div>

                    <p className="text-sm whitespace-pre-wrap text-foreground">
                        {trend.summary || <span className="text-muted-foreground">No summary provided.</span>}
                    </p>

                    {trend.platforms.length > 0 && (
                        <div className="flex flex-wrap gap-1.5">
                            {trend.platforms.map((platform, i) => (
                                <Badge key={i} variant="outline">{platform}</Badge>
                            ))}
                        </div>
                    )}

                    <div className="flex items-center gap-2 text-xs text-muted-foreground">
                        Confidence:
                        <div className="h-1.5 w-32 overflow-hidden rounded-full bg-muted">
                            <div
                                className="h-full rounded-full bg-primary"
                                style={{ width: `${Math.round(Math.min(1, Math.max(0, trend.confidence)) * 100)}%` }}
                            />
                        </div>
                        {Math.round(trend.confidence * 100)}%
                    </div>
                </div>
            </div>
        </>
    );
}
