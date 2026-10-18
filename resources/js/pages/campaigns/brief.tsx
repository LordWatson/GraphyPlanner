import { router, useForm } from '@inertiajs/react';
import { Download, FileText, History, Send, ShieldAlert } from 'lucide-react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import { BriefAiDraft } from '@/components/campaigns/brief-ai-draft';
import { CampaignLayout } from '@/components/campaigns/campaign-layout';
import type { CampaignWorkspaceProps } from '@/components/campaigns/types';
import { confirmDialog } from '@/components/confirm-dialog';
import InputError from '@/components/input-error';
import { EmptyState, Panel } from '@/components/page';
import { StatusPill } from '@/components/status-pill';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { formatDateTime } from '@/lib/dates';
import { index as campaignsIndex } from '@/routes/campaigns';
import {
    pdf as briefPdf,
    send as sendBrief,
    update as updateBrief,
} from '@/routes/campaigns/brief';

export type BriefContent = {
    background: string | null;
    objective_detail: string | null;
    audience: string | null;
    key_messages: string[];
    offer: string | null;
    call_to_action: string | null;
    mandatories: string[];
    avoid: string[];
    deliverables_summary: string | null;
    success_measures: string | null;
};

type Revision = {
    id: number;
    number: number;
    sent_at: string;
    sent_by: string | null;
    decision: 'approved' | 'changes_requested' | null;
    decision_comment: string | null;
    decided_by: string | null;
    decided_at: string | null;
    content_hash: string;
};

type BriefProps = CampaignWorkspaceProps & {
    brief:
        | (BriefContent & {
              status: string;
              status_label: string;
              approved_at: string | null;
              approver: string | null;
              updated_at: string | null;
          })
        | null;
    revisions: Revision[];
    brandAvoid: string[];
    hasPortalContacts: boolean;
};

export const BRIEF_SECTIONS: {
    key: keyof BriefContent;
    label: string;
    hint: string;
    list?: boolean;
    rows?: number;
}[] = [
    {
        key: 'background',
        label: 'Background',
        hint: 'What’s happening and why this campaign, now.',
        rows: 4,
    },
    {
        key: 'objective_detail',
        label: 'Objective',
        hint: 'What success looks like, in a sentence or two.',
    },
    {
        key: 'audience',
        label: 'Audience',
        hint: 'Who we’re talking to and what they care about.',
    },
    {
        key: 'key_messages',
        label: 'Key messages',
        hint: 'One per line. The few things every post should land.',
        list: true,
    },
    {
        key: 'offer',
        label: 'Offer',
        hint: 'The product, deal or reason to act.',
    },
    {
        key: 'call_to_action',
        label: 'Call to action',
        hint: 'The one thing we want people to do.',
        rows: 1,
    },
    {
        key: 'mandatories',
        label: 'Mandatories',
        hint: 'One per line. Legal lines, hashtags, product names that must appear.',
        list: true,
    },
    {
        key: 'avoid',
        label: 'Avoid',
        hint: 'One per line. Claims, words or topics to stay clear of.',
        list: true,
    },
    {
        key: 'deliverables_summary',
        label: 'Deliverables',
        hint: 'What we’ll make, roughly: formats, channels, volume.',
    },
    {
        key: 'success_measures',
        label: 'Success measures',
        hint: 'How we’ll know it worked. Numbers help.',
    },
];

const LETTERS = 'ABCDEFGHIJ';

type FormShape = Record<keyof BriefContent, string>;

function toForm(brief: BriefContent | null): FormShape {
    const shape = {} as FormShape;

    BRIEF_SECTIONS.forEach(({ key, list }) => {
        const value = brief?.[key];
        shape[key] = list
            ? ((value as string[] | undefined) ?? []).join('\n')
            : ((value as string | null | undefined) ?? '');
    });

    return shape;
}

/** A ruled "spec" row (book §1.2): mono letter + label left, content right. */
export function SpecRow({
    index,
    label,
    hint,
    children,
}: {
    index: number;
    label: string;
    hint?: string;
    children: ReactNode;
}) {
    return (
        <div className="border-border grid grid-cols-1 gap-2 border-t py-5 sm:grid-cols-[220px_minmax(0,1fr)] sm:gap-8">
            <div className="flex flex-col gap-1 pt-1">
                <dt className="eyebrow text-foreground">
                    {LETTERS[index]} — {label}
                </dt>
                {hint && (
                    <p className="text-muted-foreground text-xs text-pretty">
                        {hint}
                    </p>
                )}
            </div>
            <dd className="min-w-0">{children}</dd>
        </div>
    );
}

/**
 * Steps C-2.1 / C-2.3 — the campaign brief: a sectioned editor laid out as the brand book's ruled
 * spec rows, read-only for Designers and Viewers. "Send to client" freezes a revision the client
 * signs off in the portal; editing a signed brief puts it back to draft (the server enforces it).
 */
export default function CampaignBrief(props: BriefProps) {
    const { campaign, can, brief, revisions, brandAvoid, hasPortalContacts } =
        props;
    const form = useForm<FormShape>(toForm(brief));
    const [sending, setSending] = useState(false);
    const editable = can.update;
    const status = brief?.status ?? 'draft';
    const hasContent =
        brief !== null &&
        BRIEF_SECTIONS.some(({ key }) => {
            const value = brief[key];

            return Array.isArray(value) ? value.length > 0 : Boolean(value);
        });

    const save = () => {
        const payload: Record<string, string | string[] | null> = {};

        BRIEF_SECTIONS.forEach(({ key, list }) => {
            const value = form.data[key];
            payload[key] = list
                ? value
                      .split('\n')
                      .map((line) => line.trim())
                      .filter(Boolean)
                : value.trim() || null;
        });

        form.transform(() => payload);
        form.put(updateBrief.url(campaign.id), { preserveScroll: true });
    };

    const send = async () => {
        if (form.isDirty) {
            await confirmDialog({
                title: 'Save your changes first.',
                description:
                    'The client gets the saved brief. Save, then send.',
                confirmLabel: 'Got it',
            });

            return;
        }

        if (
            !(await confirmDialog({
                title: 'Send this brief for sign-off?',
                description: hasPortalContacts
                    ? 'We’ll freeze this version as a new revision and email your client’s portal contacts to approve it.'
                    : 'This client has no portal contacts yet, so nobody will be emailed. Invite someone from the client’s Portal access tab first.',
                confirmLabel: 'Send to client',
            }))
        ) {
            return;
        }

        router.post(
            sendBrief.url(campaign.id),
            {},
            {
                preserveScroll: true,
                onStart: () => setSending(true),
                onFinish: () => setSending(false),
            },
        );
    };

    return (
        <CampaignLayout {...props}>
            <section className="flex flex-col gap-6">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                    <div className="flex flex-col gap-2">
                        <span className="eyebrow text-muted-foreground">
                            The brief
                        </span>
                        <h2 className="font-display text-[1.75rem] leading-tight">
                            {status === 'approved' ? (
                                <>
                                    Signed off.{' '}
                                    <em className="text-primary not-italic">
                                        Go.
                                    </em>
                                </>
                            ) : status === 'sent' ? (
                                'With the client for sign-off.'
                            ) : status === 'changes_requested' ? (
                                'The client asked for changes.'
                            ) : (
                                'What we’re making, and why.'
                            )}
                        </h2>
                        <div className="flex flex-wrap items-center gap-2">
                            <StatusPill
                                kind="brief"
                                value={status}
                                label={brief?.status_label ?? 'Draft'}
                            />
                            {brief?.approved_at && (
                                <span className="text-muted-foreground text-xs">
                                    Approved by {brief.approver ?? 'the client'}{' '}
                                    on {formatDateTime(brief.approved_at)}
                                </span>
                            )}
                            {form.isDirty && (
                                <Badge variant="warning">Unsaved changes</Badge>
                            )}
                        </div>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        {editable && can.useAi && (
                            <BriefAiDraft
                                campaignId={campaign.id}
                                current={form.data}
                                onAccept={(key, value) =>
                                    form.setData(key, value)
                                }
                            />
                        )}
                        {hasContent && (
                            <Button asChild variant="outline">
                                <a
                                    href={briefPdf.url(campaign.id)}
                                    target="_blank"
                                    rel="noreferrer"
                                >
                                    <Download />
                                    PDF
                                </a>
                            </Button>
                        )}
                        {editable && (
                            <Button
                                variant="outline"
                                onClick={save}
                                disabled={form.processing || !form.isDirty}
                            >
                                Save brief
                            </Button>
                        )}
                        {editable && status !== 'approved' && hasContent && (
                            <Button
                                onClick={send}
                                disabled={sending || status === 'sent'}
                            >
                                <Send />
                                {status === 'sent'
                                    ? 'Sent'
                                    : status === 'changes_requested'
                                      ? 'Send again'
                                      : 'Send to client'}
                            </Button>
                        )}
                    </div>
                </div>

                {!editable && !hasContent ? (
                    <EmptyState
                        icon={FileText}
                        title="No brief yet."
                        description="The strategist writes the brief. It shows up here once it’s started."
                    />
                ) : (
                    <dl className="border-foreground/85 [&>div:first-child]:border-foreground/85 flex flex-col border-b">
                        {BRIEF_SECTIONS.map((section, index) => (
                            <SpecRow
                                key={section.key}
                                index={index}
                                label={section.label}
                                hint={editable ? section.hint : undefined}
                            >
                                {editable ? (
                                    <div className="flex flex-col gap-2">
                                        <Textarea
                                            aria-label={section.label}
                                            rows={
                                                section.rows ??
                                                (section.list ? 4 : 3)
                                            }
                                            value={form.data[section.key]}
                                            onChange={(event) =>
                                                form.setData(
                                                    section.key,
                                                    event.target.value,
                                                )
                                            }
                                            className="bg-card text-[15px] leading-relaxed"
                                        />
                                        <InputError
                                            message={
                                                (
                                                    form.errors as Record<
                                                        string,
                                                        string | undefined
                                                    >
                                                )[section.key] ??
                                                (
                                                    form.errors as Record<
                                                        string,
                                                        string | undefined
                                                    >
                                                )[`${section.key}.0`]
                                            }
                                        />
                                        {section.key === 'avoid' &&
                                            brandAvoid.length > 0 && (
                                                <div className="flex flex-wrap items-center gap-1.5">
                                                    <span className="text-muted-foreground inline-flex items-center gap-1 text-xs">
                                                        <ShieldAlert className="size-3.5" />
                                                        Brand Brain already
                                                        rules out:
                                                    </span>
                                                    {brandAvoid.map(
                                                        (phrase) => (
                                                            <Badge
                                                                key={phrase}
                                                                variant="lilac"
                                                            >
                                                                {phrase}
                                                            </Badge>
                                                        ),
                                                    )}
                                                </div>
                                            )}
                                    </div>
                                ) : (
                                    <ReadOnlyValue
                                        value={brief?.[section.key] ?? null}
                                    />
                                )}
                            </SpecRow>
                        ))}
                    </dl>
                )}
            </section>

            {revisions.length > 0 && (
                <Panel
                    icon={History}
                    eyebrow="Sign-off record"
                    title="Revisions"
                >
                    <ol className="divide-border/70 flex flex-col divide-y">
                        {revisions.map((revision) => (
                            <li
                                key={revision.id}
                                className="flex flex-col gap-1.5 py-3 first:pt-0 last:pb-0 sm:flex-row sm:items-start sm:justify-between"
                            >
                                <div className="flex min-w-0 flex-col gap-1">
                                    <span className="text-sm font-semibold">
                                        Revision {revision.number}
                                    </span>
                                    <span className="text-muted-foreground text-xs">
                                        Sent {formatDateTime(revision.sent_at)}
                                        {revision.sent_by
                                            ? ` by ${revision.sent_by}`
                                            : ''}
                                    </span>
                                    {revision.decision_comment && (
                                        <p className="border-lilac bg-lilac/15 mt-1 rounded-lg border-l-2 px-3 py-2 text-sm">
                                            “{revision.decision_comment}”
                                        </p>
                                    )}
                                    <span
                                        className="text-muted-foreground/80 truncate font-mono text-[10px]"
                                        title={revision.content_hash}
                                    >
                                        SHA-256{' '}
                                        {revision.content_hash.slice(0, 16)}…
                                    </span>
                                </div>
                                <div className="flex shrink-0 flex-col items-start gap-1 sm:items-end">
                                    {revision.decision ? (
                                        <StatusPill
                                            kind="approval"
                                            value={revision.decision}
                                        />
                                    ) : (
                                        <StatusPill
                                            kind="brief"
                                            value="sent"
                                            label="Awaiting decision"
                                        />
                                    )}
                                    {revision.decided_at && (
                                        <span className="text-muted-foreground text-xs">
                                            {revision.decided_by} ·{' '}
                                            {formatDateTime(
                                                revision.decided_at,
                                            )}
                                        </span>
                                    )}
                                </div>
                            </li>
                        ))}
                    </ol>
                </Panel>
            )}
        </CampaignLayout>
    );
}

function ReadOnlyValue({ value }: { value: string | string[] | null }) {
    if (Array.isArray(value)) {
        return value.length === 0 ? (
            <p className="text-muted-foreground text-sm">Nothing listed.</p>
        ) : (
            <ul className="flex flex-col gap-1.5">
                {value.map((line, index) => (
                    <li
                        key={index}
                        className="flex gap-2.5 text-[15px] leading-relaxed"
                    >
                        <span
                            aria-hidden
                            className="bg-primary mt-2.5 size-1.5 shrink-0 rounded-full"
                        />
                        {line}
                    </li>
                ))}
            </ul>
        );
    }

    return value ? (
        <p className="max-w-2xl text-[15px] leading-relaxed text-pretty whitespace-pre-line">
            {value}
        </p>
    ) : (
        <p className="text-muted-foreground text-sm">Not written yet.</p>
    );
}

CampaignBrief.layout = {
    breadcrumbs: [{ title: 'Campaigns', href: campaignsIndex() }],
};
