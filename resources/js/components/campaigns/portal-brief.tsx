import { Form } from '@inertiajs/react';
import { CheckCircle2, Download, MessageSquareWarning } from 'lucide-react';
import InputError from '@/components/input-error';
import { StatusPill } from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { briefSectionLabels } from '@/lib/campaigns';
import { formatDate } from '@/lib/dates';
import {
    decide as decideBrief,
    pdf as briefPdf,
} from '@/routes/portal/campaigns/brief';

export type PortalBrief = {
    status: string;
    revision_id: number;
    number: number;
    content_hash: string;
    content: Record<string, string | string[] | null>;
    sent_at: string;
    decision: 'approved' | 'changes_requested' | null;
    decided_at: string | null;
    can_decide: boolean;
};

const LETTERS = 'ABCDEFGHIJ';

/**
 * Step C-2.3 / C-5.1 — the brief as the client sees it: the exact revision the studio sent, laid
 * out as the book's ruled spec rows, with Approve / Request changes (a note is required for
 * changes). The decision carries the revision id and its hash, so a stale page can't sign a newer
 * version.
 */
export function PortalBriefSection({
    campaignId,
    brief,
}: {
    campaignId: number;
    brief: PortalBrief;
}) {
    const sections = Object.entries(briefSectionLabels).filter(([key]) => {
        const value = brief.content[key];

        return Array.isArray(value) ? value.length > 0 : Boolean(value);
    });

    return (
        <section id="brief" className="flex scroll-mt-24 flex-col gap-5">
            <div className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                <div className="flex flex-col gap-2">
                    <span className="eyebrow text-muted-foreground">
                        The brief · revision {brief.number}
                    </span>
                    <h2 className="font-display text-[1.75rem] leading-tight">
                        {brief.can_decide ? (
                            <>
                                Ready for your{' '}
                                <em className="text-primary not-italic">
                                    sign-off
                                </em>
                                .
                            </>
                        ) : brief.status === 'approved' ? (
                            'Signed off. Thank you.'
                        ) : brief.status === 'changes_requested' ? (
                            'We’re working on your changes.'
                        ) : (
                            'The studio is updating it.'
                        )}
                    </h2>
                    <div className="flex flex-wrap items-center gap-2">
                        <StatusPill kind="brief" value={brief.status} />
                        <span className="text-muted-foreground text-xs">
                            Sent {formatDate(brief.sent_at)}
                        </span>
                    </div>
                </div>
                <Button asChild variant="outline">
                    <a
                        href={briefPdf.url(campaignId)}
                        target="_blank"
                        rel="noreferrer"
                    >
                        <Download />
                        PDF
                    </a>
                </Button>
            </div>

            <dl className="border-foreground/85 flex flex-col border-t border-b">
                {sections.map(([key, label], index) => {
                    const value = brief.content[key];

                    return (
                        <div
                            key={key}
                            className="border-border grid gap-2 border-t py-4 first:border-t-0 sm:grid-cols-[200px_minmax(0,1fr)] sm:gap-8"
                        >
                            <dt className="eyebrow text-foreground pt-1">
                                {LETTERS[index]} — {label}
                            </dt>
                            <dd className="text-[15px] leading-relaxed">
                                {Array.isArray(value) ? (
                                    <ul className="flex flex-col gap-1.5">
                                        {value.map((line, i) => (
                                            <li
                                                key={i}
                                                className="flex gap-2.5"
                                            >
                                                <span
                                                    aria-hidden
                                                    className="bg-primary mt-2.5 size-1.5 shrink-0 rounded-full"
                                                />
                                                {line}
                                            </li>
                                        ))}
                                    </ul>
                                ) : (
                                    <p className="text-pretty whitespace-pre-line">
                                        {value}
                                    </p>
                                )}
                            </dd>
                        </div>
                    );
                })}
            </dl>

            {brief.can_decide && (
                <Form
                    {...decideBrief.form(campaignId)}
                    transform={(data) => ({
                        ...data,
                        revision_id: brief.revision_id,
                        content_hash: brief.content_hash,
                    })}
                    options={{ preserveScroll: true }}
                    className="border-info/25 bg-card ring-info/5 flex flex-col gap-4 rounded-2xl border p-5 ring-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <p className="font-display text-xl leading-tight">
                                Is this the campaign you want?
                            </p>
                            <div className="grid gap-1.5">
                                <textarea
                                    name="comment"
                                    rows={3}
                                    aria-label="Note for the studio"
                                    placeholder="Anything to change? Tell us here (needed if you ask for changes)."
                                    className="border-input bg-background placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-ring/40 flex w-full rounded-xl border px-3.5 py-2.5 text-sm shadow-xs outline-none focus-visible:ring-[3px]"
                                />
                                <InputError
                                    message={
                                        errors.comment ?? errors.revision_id
                                    }
                                />
                            </div>
                            <div className="grid gap-2.5 sm:grid-cols-2">
                                <Button
                                    type="submit"
                                    name="decision"
                                    value="approved"
                                    size="lg"
                                    className="bg-success text-success-foreground hover:bg-success/90 h-13 text-base"
                                    disabled={processing}
                                >
                                    <CheckCircle2 className="size-5" />
                                    Approve the brief
                                </Button>
                                <Button
                                    type="submit"
                                    name="decision"
                                    value="changes_requested"
                                    size="lg"
                                    variant="outline"
                                    className="border-destructive/35 text-destructive hover:border-destructive/60 hover:bg-destructive/5 hover:text-destructive h-13 text-base"
                                    disabled={processing}
                                >
                                    <MessageSquareWarning className="size-5" />
                                    Request changes
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            )}
        </section>
    );
}
