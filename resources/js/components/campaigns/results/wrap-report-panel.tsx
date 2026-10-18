import { router, useForm } from '@inertiajs/react';
import { Download, FileBarChart, Send, Sparkles } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { AiSuggestionSheet } from '@/components/campaigns/ai-suggestion-sheet';
import type { SuggestionSection } from '@/components/campaigns/ai-suggestion-sheet';
import { confirmDialog } from '@/components/confirm-dialog';
import InputError from '@/components/input-error';
import { Panel } from '@/components/page';
import { StatusPill } from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { postJson } from '@/lib/campaigns';
import { formatDateTime } from '@/lib/dates';
import { wrap as aiWrap } from '@/routes/campaigns/ai';
import {
    pdf as reportPdf,
    share as shareReport,
    update as updateReport,
} from '@/routes/campaigns/report';

export type WrapReport = {
    summary: string | null;
    highlights: string[];
    learnings: string | null;
    next_steps: string | null;
    status: 'draft' | 'shared';
    version: number;
    shared_at: string | null;
    shared_by: string | null;
};

type Fields = {
    summary: string;
    highlights: string;
    learnings: string;
    next_steps: string;
};

const LABELS: Record<keyof Fields, string> = {
    summary: 'Summary',
    highlights: 'Highlights (one per line)',
    learnings: 'What we learned',
    next_steps: 'What’s next',
};

/**
 * Step C-7.3 — the wrap report: commentary the studio writes (or drafts with AI and accepts per
 * section), a branded PDF, and "Share with client", which freezes the KPI numbers as they stand.
 */
export function WrapReportPanel({
    campaignId,
    report,
    editable,
    useAi,
}: {
    campaignId: number;
    report: WrapReport | null;
    editable: boolean;
    useAi: boolean;
}) {
    const form = useForm<Fields>({
        summary: report?.summary ?? '',
        highlights: (report?.highlights ?? []).join('\n'),
        learnings: report?.learnings ?? '',
        next_steps: report?.next_steps ?? '',
    });
    const [drafting, setDrafting] = useState(false);
    const [sheet, setSheet] = useState<SuggestionSection[] | null>(null);

    const save = () => {
        form.transform((data) => ({
            summary: data.summary.trim() || null,
            highlights: data.highlights
                .split('\n')
                .map((line) => line.trim())
                .filter(Boolean),
            learnings: data.learnings.trim() || null,
            next_steps: data.next_steps.trim() || null,
        }));
        form.put(updateReport.url(campaignId), { preserveScroll: true });
    };

    const share = async () => {
        if (form.isDirty) {
            toast.error('Save the report first, then share it.');

            return;
        }

        if (
            await confirmDialog({
                title:
                    report?.status === 'shared'
                        ? 'Share the updated report?'
                        : 'Share the report with the client?',
                description:
                    'We freeze today’s numbers in it, publish it to their portal and email them the PDF.',
                confirmLabel: 'Share with client',
            })
        ) {
            router.post(
                shareReport.url(campaignId),
                {},
                { preserveScroll: true },
            );
        }
    };

    const draft = async () => {
        setDrafting(true);
        const result = await postJson<{
            draft: {
                fields: Record<string, string | string[] | null>;
                flags: Record<string, string[]>;
            };
        }>(aiWrap.url(campaignId));
        setDrafting(false);

        if (!result.ok) {
            toast.error(result.message);

            return;
        }

        const { fields, flags } = result.data.draft;
        setSheet(
            (Object.keys(LABELS) as (keyof Fields)[]).map((key) => {
                const value = fields[key];

                return {
                    key,
                    label: LABELS[key],
                    current: form.data[key],
                    suggested: Array.isArray(value)
                        ? value.join('\n')
                        : (value ?? ''),
                    flags: flags[key] ?? [],
                };
            }),
        );
    };

    return (
        <Panel
            icon={FileBarChart}
            eyebrow="Wrap report"
            title={
                report?.status === 'shared'
                    ? 'Shared with the client'
                    : 'The story of the campaign'
            }
            actions={
                report && (
                    <StatusPill
                        kind="brief"
                        value={
                            report.status === 'shared' ? 'approved' : 'draft'
                        }
                        label={
                            report.status === 'shared'
                                ? `Shared · v${report.version}`
                                : 'Draft'
                        }
                    />
                )
            }
        >
            <div className="flex flex-col gap-5">
                {report?.shared_at && (
                    <p className="text-muted-foreground text-xs">
                        Last shared {formatDateTime(report.shared_at)}
                        {report.shared_by ? ` by ${report.shared_by}` : ''}.
                        Numbers in that version are frozen.
                    </p>
                )}

                {(Object.keys(LABELS) as (keyof Fields)[]).map((key) => (
                    <div key={key} className="grid gap-1.5">
                        <Label htmlFor={`report-${key}`}>{LABELS[key]}</Label>
                        {editable ? (
                            <Textarea
                                id={`report-${key}`}
                                rows={key === 'summary' ? 3 : 4}
                                value={form.data[key]}
                                onChange={(event) =>
                                    form.setData(key, event.target.value)
                                }
                            />
                        ) : (
                            <p className="text-sm whitespace-pre-line">
                                {form.data[key] || (
                                    <span className="text-muted-foreground">
                                        Not written yet.
                                    </span>
                                )}
                            </p>
                        )}
                        <InputError
                            message={
                                (
                                    form.errors as Record<
                                        string,
                                        string | undefined
                                    >
                                )[key]
                            }
                        />
                    </div>
                ))}

                <div className="flex flex-wrap items-center gap-2">
                    {editable && useAi && (
                        <Button
                            variant="secondary"
                            onClick={draft}
                            disabled={drafting}
                        >
                            {drafting ? <Spinner /> : <Sparkles />}
                            {drafting ? 'Drafting…' : 'Draft with AI'}
                        </Button>
                    )}
                    {editable && (
                        <Button
                            variant="outline"
                            onClick={save}
                            disabled={form.processing || !form.isDirty}
                        >
                            Save report
                        </Button>
                    )}
                    {report && (
                        <Button asChild variant="outline">
                            <a
                                href={reportPdf.url(campaignId)}
                                target="_blank"
                                rel="noreferrer"
                            >
                                <Download />
                                PDF
                            </a>
                        </Button>
                    )}
                    {editable && report?.summary && (
                        <Button onClick={share}>
                            <Send />
                            {report.status === 'shared'
                                ? 'Share again'
                                : 'Share with client'}
                        </Button>
                    )}
                </div>
            </div>

            {sheet && (
                <AiSuggestionSheet
                    open={sheet !== null}
                    onOpenChange={(open) => !open && setSheet(null)}
                    title="A first draft of the wrap"
                    description="From the targets, the brief’s success measures and the top posts."
                    sections={sheet}
                    onAccept={(key, value) =>
                        form.setData(key as keyof Fields, value)
                    }
                />
            )}
        </Panel>
    );
}
