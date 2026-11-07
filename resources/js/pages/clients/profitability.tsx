import { Head, Link } from '@inertiajs/react';
import { TriangleAlert } from 'lucide-react';
import { EmptyState, PageHeader } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { index as clientsIndex } from '@/routes/clients';
import { edit as organizationSettings } from '@/routes/organization';

type Row = {
    client_id: number;
    client_name: string;
    revenue: string;
    cost: string | null;
    margin: string | null;
    margin_pct: number | null;
    hours: number;
    /** False when the agency tracks this client's hours itself. */
    inferred: boolean;
    effort: {
        posts: number;
        revision_rounds: number;
        assets: number;
        inbox_replies: number;
        reports: number;
    };
    revisions_recent: number;
    revisions_previous: number;
    revisions_climbing: boolean;
};

export default function ClientProfitability({
    clients,
    months,
    rateConfigured,
}: {
    clients: Row[];
    months: number;
    rateConfigured: boolean;
}) {
    return (
        <>
            <Head title="Client profitability" />
            <div className="mx-auto flex w-full max-w-4xl flex-col gap-8 px-4 py-6 md:px-8 md:py-9">
                <PageHeader
                    eyebrow={`Last ${months} months`}
                    title={
                        <>
                            Client <em>profitability</em>.
                        </>
                    }
                    description="Money received against the work we put in. Effort is estimated from what Alima saw happen — posts, revision rounds, assets, inbox replies and reports — so read it as a direction, not an invoice."
                />

                {!rateConfigured && (
                    <Alert variant="destructive">
                        <TriangleAlert />
                        <AlertTitle>No blended hourly rate set</AlertTitle>
                        <AlertDescription>
                            <p>
                                Without it there is no margin to show — only the
                                effort. Set one in{' '}
                                <Link
                                    className="underline"
                                    href={organizationSettings()}
                                >
                                    organisation settings
                                </Link>
                                .
                            </p>
                        </AlertDescription>
                    </Alert>
                )}

                {clients.length === 0 ? (
                    <EmptyState
                        title="No clients yet."
                        description="This fills in once you have a client with a paid invoice."
                    />
                ) : (
                    <div className="grid gap-3">
                        {clients.map((row) => (
                            <div
                                key={row.client_id}
                                className="border-border bg-card grid gap-3 rounded-2xl border p-4 shadow-sm sm:p-5"
                            >
                                <div className="flex flex-wrap items-baseline justify-between gap-2">
                                    <h2 className="font-medium">
                                        {row.client_name}
                                    </h2>
                                    {row.margin === null ? (
                                        <span className="text-muted-foreground text-sm">
                                            Margin unavailable
                                        </span>
                                    ) : (
                                        <span className="font-mono text-sm">
                                            {row.margin}
                                            {row.margin_pct !== null && (
                                                <span className="text-muted-foreground">
                                                    {' '}
                                                    ({row.margin_pct}%)
                                                </span>
                                            )}
                                        </span>
                                    )}
                                </div>

                                <dl className="text-muted-foreground grid grid-cols-2 gap-x-6 gap-y-1 text-sm sm:grid-cols-4">
                                    <div>
                                        <dt className="eyebrow">Received</dt>
                                        <dd className="font-mono">
                                            {row.revenue}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt className="eyebrow">
                                            {row.inferred
                                                ? 'Hours (est.)'
                                                : 'Hours (tracked)'}
                                        </dt>
                                        <dd className="font-mono">
                                            {row.hours}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt className="eyebrow">Cost</dt>
                                        <dd className="font-mono">
                                            {row.cost ?? '—'}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt className="eyebrow">Posts</dt>
                                        <dd className="font-mono">
                                            {row.effort.posts}
                                        </dd>
                                    </div>
                                </dl>

                                {/* The pattern that quietly destroys a margin, and which no
                                    per-post view shows. */}
                                {row.revisions_climbing && (
                                    <p className="text-sm">
                                        <Badge variant="outline">
                                            Revisions climbing
                                        </Badge>{' '}
                                        <span className="text-muted-foreground">
                                            {row.revisions_previous} →{' '}
                                            {row.revisions_recent} rounds. Worth
                                            asking whether the brief or the
                                            retainer needs revisiting.
                                        </span>
                                    </p>
                                )}
                            </div>
                        ))}
                    </div>
                )}

                <p className="text-muted-foreground text-sm">
                    Internal only — this never appears in a client report. Set a
                    client&rsquo;s own monthly hours on their page to use your
                    tracked figure instead of the estimate.{' '}
                    <Link className="underline" href={clientsIndex()}>
                        Back to clients
                    </Link>
                </p>
            </div>
        </>
    );
}

ClientProfitability.layout = {
    breadcrumbs: [
        { title: 'Clients', href: clientsIndex() },
        { title: 'Profitability', href: clientsIndex() },
    ],
};
