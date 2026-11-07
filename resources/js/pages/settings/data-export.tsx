import { Form, Head } from '@inertiajs/react';
import { Download } from 'lucide-react';
import Heading from '@/components/heading';
import { EmptyState } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { download, index, store } from '@/routes/data-export';

type Export = {
    id: number;
    status: string;
    status_label: string;
    requested_by: string | null;
    requested_at: string | null;
    rows: number | null;
    size: string | null;
    expires_at: string | null;
    failure_reason: string | null;
    downloadable: boolean;
};

/**
 * Settings → Data export (Owner only). The portability half of GDPR as a product feature: an owner
 * takes everything the app holds for their organisation without asking anyone.
 */
export default function DataExport({
    exports,
    retentionDays,
    pending,
}: {
    exports: Export[];
    retentionDays: number;
    pending: boolean;
}) {
    return (
        <>
            <Head title="Data export" />

            <h1 className="sr-only">Data export</h1>

            <div className="space-y-8">
                <Heading
                    variant="small"
                    title="Data export"
                    description="Take everything we hold for your organisation as one archive — clients, posts, campaigns, invoices, inbox threads and the rest, one spreadsheet per set of records. It is yours, so you never have to ask us for it."
                />

                <div className="border-border bg-card grid gap-3 rounded-2xl border p-5">
                    <p className="text-sm">
                        An export takes a few minutes. You&rsquo;ll get a
                        notification when it&rsquo;s ready, and the archive
                        stays downloadable for {retentionDays}{' '}
                        {retentionDays === 1 ? 'day' : 'days'} before we delete
                        it.
                    </p>
                    <p className="text-muted-foreground text-sm">
                        Media files aren&rsquo;t in the archive — it would run
                        to many gigabytes. Every asset is listed with its
                        details so you know what to download from the library.
                        API keys, access tokens and password hashes are left
                        out: we never write a credential into a file that leaves
                        our servers.
                    </p>
                    <Form {...store.form()} options={{ preserveScroll: true }}>
                        {({ processing }) => (
                            <Button
                                className="justify-self-start"
                                disabled={processing || pending}
                            >
                                {pending
                                    ? 'Preparing your export…'
                                    : 'Request an export'}
                            </Button>
                        )}
                    </Form>
                </div>

                {exports.length === 0 ? (
                    <EmptyState
                        title="No exports yet."
                        description="Request one above and it will appear here."
                    />
                ) : (
                    <ul className="divide-border divide-y">
                        {exports.map((row) => (
                            <li
                                key={row.id}
                                className="flex flex-wrap items-center justify-between gap-3 py-3"
                            >
                                <span className="grid gap-0.5">
                                    <span className="flex items-center gap-2 text-sm font-medium">
                                        <Badge variant="outline">
                                            {row.status_label}
                                        </Badge>
                                        {row.requested_at}
                                    </span>
                                    <span className="text-muted-foreground text-xs">
                                        {row.failure_reason ??
                                            [
                                                row.requested_by &&
                                                    `Requested by ${row.requested_by}`,
                                                row.rows !== null &&
                                                    `${row.rows.toLocaleString()} rows`,
                                                row.size,
                                                row.downloadable &&
                                                    row.expires_at &&
                                                    `available until ${row.expires_at}`,
                                            ]
                                                .filter(Boolean)
                                                .join(' · ')}
                                    </span>
                                </span>
                                {row.downloadable && (
                                    <Button asChild variant="outline" size="sm">
                                        {/* A plain link, not an Inertia visit: it streams a file. */}
                                        <a href={download.url(row.id)}>
                                            <Download />
                                            Download
                                        </a>
                                    </Button>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </>
    );
}

DataExport.layout = {
    breadcrumbs: [{ title: 'Data export', href: index() }],
};
