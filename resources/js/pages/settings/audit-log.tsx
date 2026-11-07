import { Head, Link, router } from '@inertiajs/react';
import { Download } from 'lucide-react';
import { useState } from 'react';
import Heading from '@/components/heading';
import { EmptyState } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { exportMethod, index } from '@/routes/audit-log';

const ANYONE = 'anyone';

type Entry = {
    at: string;
    source: string;
    action: string;
    actor: string | null;
    subject: string | null;
    detail: string | null;
};

type Props = {
    entries: Entry[];
    total: number;
    onScreen: number;
    timezone: string;
    filters: { from: string; to: string; actor: number | null };
    members: { value: string; label: string }[];
};

/**
 * Settings → Audit log (Owner only). One stream of who did what and when, across staff changes,
 * post approvals, campaign moves and plan changes — the answer to the question a client's
 * procurement team and every security questionnaire asks.
 */
export default function AuditLog({
    entries,
    total,
    onScreen,
    timezone,
    filters,
    members,
}: Props) {
    const [from, setFrom] = useState(filters.from);
    const [to, setTo] = useState(filters.to);
    const [actor, setActor] = useState(
        filters.actor === null ? ANYONE : String(filters.actor),
    );

    const query = {
        from,
        to,
        actor: actor === ANYONE ? undefined : actor,
    };

    return (
        <>
            <Head title="Audit log" />

            <h1 className="sr-only">Audit log</h1>

            <div className="space-y-8">
                <Heading
                    variant="small"
                    title="Audit log"
                    description={`Who did what, and when. Staff and role changes, post approvals, campaign moves and plan changes, newest first. Times are ${timezone}.`}
                />

                <form
                    className="grid gap-4 sm:grid-cols-[1fr_1fr_1fr_auto] sm:items-end"
                    onSubmit={(event) => {
                        event.preventDefault();
                        router.get(index.url(), query, {
                            preserveState: true,
                            preserveScroll: true,
                        });
                    }}
                >
                    <div className="grid gap-2">
                        <Label htmlFor="from">From</Label>
                        <Input
                            id="from"
                            type="date"
                            value={from}
                            onChange={(event) => setFrom(event.target.value)}
                        />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="to">To</Label>
                        <Input
                            id="to"
                            type="date"
                            value={to}
                            onChange={(event) => setTo(event.target.value)}
                        />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="actor">Person</Label>
                        <Select value={actor} onValueChange={setActor}>
                            <SelectTrigger id="actor" aria-label="Person">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={ANYONE}>Anyone</SelectItem>
                                {members.map((member) => (
                                    <SelectItem
                                        key={member.value}
                                        value={member.value}
                                    >
                                        {member.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                    <Button type="submit" variant="outline">
                        Apply
                    </Button>
                </form>

                <div className="flex flex-wrap items-center justify-between gap-3">
                    <p className="text-muted-foreground text-sm">
                        {total === 0
                            ? 'Nothing in this window.'
                            : total > onScreen
                              ? `${total} entries — showing the most recent ${onScreen}. The export has all of them.`
                              : `${total} ${total === 1 ? 'entry' : 'entries'}.`}
                    </p>
                    {total > 0 && (
                        <Button asChild variant="outline" size="sm">
                            {/* A plain link, not an Inertia visit: it downloads a file. */}
                            <a href={exportMethod.url({ query })}>
                                <Download />
                                Download CSV
                            </a>
                        </Button>
                    )}
                </div>

                {entries.length === 0 ? (
                    <EmptyState
                        title="Nothing here yet."
                        description="Role changes, approvals, campaign moves and plan changes land here as they happen."
                    />
                ) : (
                    <ul className="divide-border divide-y">
                        {entries.map((entry, i) => (
                            <li
                                key={`${entry.at}-${entry.source}-${i}`}
                                className="grid gap-1 py-3"
                            >
                                <div className="flex flex-wrap items-baseline gap-2">
                                    <span className="text-muted-foreground font-mono text-xs tabular-nums">
                                        {entry.at}
                                    </span>
                                    <Badge variant="outline">
                                        {entry.source}
                                    </Badge>
                                    <span className="text-sm font-medium">
                                        {entry.action}
                                    </span>
                                </div>
                                <p className="text-muted-foreground text-sm">
                                    {entry.actor ?? 'System'}
                                    {entry.subject ? ` → ${entry.subject}` : ''}
                                    {entry.detail ? ` · ${entry.detail}` : ''}
                                </p>
                            </li>
                        ))}
                    </ul>
                )}

                <p className="text-muted-foreground text-sm">
                    Entries are written by the app as actions happen and cannot
                    be edited or removed here.{' '}
                    <Link className="underline" href={index()}>
                        Reset filters
                    </Link>
                </p>
            </div>
        </>
    );
}

AuditLog.layout = {
    breadcrumbs: [{ title: 'Audit log', href: index() }],
};
