import { Head, Link, router } from '@inertiajs/react';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { index as whatsappLog } from '@/routes/whatsapp-log';

type LogRow = {
    id: number;
    sent_at: string | null;
    recipient: string;
    masked_phone: string;
    template: string;
    notification: string;
    status: string;
    status_label: string;
    error_reason: string | null;
    delivered_at: string | null;
};

type Paginated = {
    data: LogRow[];
    links: { url: string | null; label: string; active: boolean }[];
};

const TONE: Record<
    string,
    'default' | 'secondary' | 'outline' | 'destructive'
> = {
    delivered: 'default',
    read: 'default',
    sent: 'secondary',
    failed: 'destructive',
    skipped: 'outline',
};

/**
 * Step W-4.2 — "did my client actually get it?".
 *
 * Skipped messages are listed beside delivered ones on purpose: "nothing was sent, because they
 * never consented" is the answer most of the time, and it is invisible anywhere else.
 */
export default function WhatsAppLog({
    messages,
    filters,
    statuses,
}: {
    messages: Paginated;
    filters: { status: string | null };
    statuses: { value: string; label: string }[];
}) {
    const filterBy = (status: string) =>
        router.get(whatsappLog().url, status === 'all' ? {} : { status }, {
            preserveScroll: true,
            preserveState: true,
        });

    return (
        <>
            <Head title="WhatsApp log" />
            <h1 className="sr-only">WhatsApp log</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="WhatsApp log"
                    description="Every WhatsApp message this workspace has tried to send, including the ones it decided not to."
                />

                <Select
                    value={filters.status ?? 'all'}
                    onValueChange={filterBy}
                >
                    <SelectTrigger className="w-56">
                        <SelectValue placeholder="All statuses" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">All statuses</SelectItem>
                        {statuses.map((status) => (
                            <SelectItem key={status.value} value={status.value}>
                                {status.label}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>

                {messages.data.length === 0 ? (
                    <p className="text-muted-foreground border-border rounded-xl border border-dashed p-6 text-sm">
                        Nothing yet. Messages appear here once somebody has
                        verified a number or a client contact has consented.
                    </p>
                ) : (
                    <div className="border-border overflow-hidden rounded-xl border">
                        {messages.data.map((row) => (
                            <div
                                key={row.id}
                                className="border-border flex flex-wrap items-center gap-x-4 gap-y-1 border-t px-4 py-3 first:border-t-0"
                            >
                                <span className="min-w-0 flex-1">
                                    <span className="block text-sm font-medium">
                                        {row.recipient}{' '}
                                        <span className="text-muted-foreground font-mono text-xs">
                                            {row.masked_phone}
                                        </span>
                                    </span>
                                    <span className="text-muted-foreground text-xs">
                                        {row.template} · {row.notification}
                                        {row.sent_at
                                            ? ` · ${new Date(row.sent_at).toLocaleString()}`
                                            : ''}
                                        {row.error_reason
                                            ? ` · ${row.error_reason}`
                                            : ''}
                                    </span>
                                </span>
                                <Badge
                                    variant={TONE[row.status] ?? 'secondary'}
                                >
                                    {row.status_label}
                                </Badge>
                            </div>
                        ))}
                    </div>
                )}

                {messages.links.length > 3 && (
                    <div className="flex flex-wrap gap-2">
                        {messages.links.map((link, index) => (
                            <Button
                                key={index}
                                asChild={link.url !== null}
                                variant={link.active ? 'default' : 'outline'}
                                size="sm"
                                disabled={link.url === null}
                            >
                                {link.url === null ? (
                                    <span
                                        dangerouslySetInnerHTML={{
                                            __html: link.label,
                                        }}
                                    />
                                ) : (
                                    <Link
                                        href={link.url}
                                        preserveScroll
                                        dangerouslySetInnerHTML={{
                                            __html: link.label,
                                        }}
                                    />
                                )}
                            </Button>
                        ))}
                    </div>
                )}
            </div>
        </>
    );
}

WhatsAppLog.layout = {
    breadcrumbs: [{ title: 'WhatsApp log', href: whatsappLog() }],
};
