import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Sparkles } from 'lucide-react';
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

                <div className="bg-noise relative flex flex-col gap-4 overflow-hidden rounded-2xl bg-gradient-brand-animated p-6 text-primary-foreground shadow-agency sm:p-8">
                    <span
                        aria-hidden
                        className="pointer-events-none absolute -top-16 -right-16 size-56 rounded-full bg-white/10 blur-2xl"
                    />
                    <span
                        aria-hidden
                        className="pointer-events-none absolute inset-0 bg-[radial-gradient(white_1px,transparent_1px)] bg-[size:22px_22px] opacity-[0.08]"
                    />
                    <div className="relative flex items-start justify-between gap-3">
                        <div className="flex flex-col gap-1">
                            <span className="inline-flex w-fit items-center gap-2 rounded-full bg-white/15 px-3 py-1 text-[10px] font-medium tracking-widest uppercase">
                                AI trend
                            </span>
                            <h1 className="font-serif text-2xl font-semibold tracking-tight italic sm:text-3xl">{trend.title}</h1>
                            <p className="text-sm text-primary-foreground/80">
                                For {client.name}{trend.created_at ? ` · discovered ${trend.created_at}` : ''}
                            </p>
                        </div>
                        <span className="flex size-11 shrink-0 items-center justify-center rounded-xl bg-white/15 text-primary-foreground">
                            <Sparkles className="size-5" />
                        </span>
                    </div>

                    <p className="relative text-sm whitespace-pre-wrap">
                        {trend.summary || <span className="text-primary-foreground/70">No summary provided.</span>}
                    </p>

                    {trend.platforms.length > 0 && (
                        <div className="relative flex flex-wrap gap-1.5">
                            {trend.platforms.map((platform, i) => (
                                <Badge key={i} variant="outline" className="border-white/30 bg-white/10 text-primary-foreground">{platform}</Badge>
                            ))}
                        </div>
                    )}

                    <div className="relative flex items-center gap-2 text-xs text-primary-foreground/80">
                        Confidence:
                        <div className="h-1.5 w-32 overflow-hidden rounded-full bg-white/20">
                            <div
                                className="h-full rounded-full bg-white"
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
