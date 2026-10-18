import { CheckCircle2, FileSignature, ListChecks, Trophy } from 'lucide-react';
import {
    campaignSwatch,
    campaignSwatchText,
} from '@/components/campaigns/campaign-chip';
import { StatusPill } from '@/components/status-pill';
import { cn } from '@/lib/utils';

const points = [
    {
        icon: FileSignature,
        title: 'Briefs clients sign off.',
        body: 'A structured brief, approved in the client portal and kept on record, revision by revision.',
    },
    {
        icon: ListChecks,
        title: 'Plans that build themselves.',
        body: 'Phases, a deliverables plan and a board, started from a template or drafted with AI.',
    },
    {
        icon: Trophy,
        title: 'Reports that win the renewal.',
        body: 'Targets against results, wrapped up in a branded report your client actually reads.',
    },
];

const phases = [
    { name: 'Teaser', grow: 6, colour: 'lilac' },
    { name: 'Launch', grow: 5, colour: 'teal' },
    { name: 'Sustain', grow: 12, colour: 'electric' },
    { name: 'Wrap', grow: 4, colour: 'lime' },
];

/**
 * Campaigns enhancement C-9.2 — the marketing site's Campaigns section, in the Alima voice. The
 * vignette is built from the product's own components (phase band, status pills, readiness meter)
 * so it always matches the real thing. No second orange accent: the hero keeps the page's one.
 */
export function CampaignsSection() {
    return (
        <section
            aria-labelledby="campaigns-title"
            className="grid grid-cols-1 items-center gap-12 lg:grid-cols-[1fr_1.1fr]"
        >
            <div className="flex flex-col gap-6">
                <span className="eyebrow text-primary">Campaigns</span>
                <h2
                    id="campaigns-title"
                    className="font-display text-[clamp(2.25rem,5vw,3.5rem)] leading-[1.04] text-balance"
                >
                    Plan it, sign it off, ship it,{' '}
                    <span className="text-primary">prove it.</span>
                </h2>
                <p className="text-muted-foreground max-w-xl text-lg leading-relaxed text-pretty">
                    Every campaign gets a brief your client approves, a plan
                    your team can follow, and a wrap report that shows what it
                    achieved. One place, from first idea to final numbers.
                </p>
                <ul className="flex flex-col gap-5 pt-2">
                    {points.map(({ icon: Icon, title, body }) => (
                        <li
                            key={title}
                            className="border-foreground flex gap-4 border-t pt-4"
                        >
                            <span className="bg-primary text-primary-foreground flex size-10 shrink-0 items-center justify-center rounded-full">
                                <Icon className="size-5" />
                            </span>
                            <span className="flex flex-col gap-1">
                                <span className="font-display text-xl leading-tight">
                                    {title}
                                </span>
                                <span className="text-muted-foreground text-[15px] leading-relaxed">
                                    {body}
                                </span>
                            </span>
                        </li>
                    ))}
                </ul>
            </div>

            <div
                aria-hidden
                className="border-border/70 bg-card flex flex-col gap-5 rounded-2xl border p-5 shadow-md sm:p-6"
            >
                <div className="flex items-start justify-between gap-3">
                    <div className="flex flex-col gap-1">
                        <span className="eyebrow text-muted-foreground">
                            Campaign SPR26 · Northwind
                        </span>
                        <span className="font-display text-2xl leading-tight">
                            Spring launch
                        </span>
                        <span className="text-sm">Live. 12 days left.</span>
                    </div>
                    <StatusPill kind="campaign" value="active" />
                </div>
                <div className="flex h-8 overflow-hidden rounded-full">
                    {phases.map((phase) => (
                        <span
                            key={phase.name}
                            className={cn(
                                'flex items-center justify-center px-2 text-[11px] font-medium',
                                campaignSwatch(phase.colour),
                                campaignSwatchText(phase.colour),
                            )}
                            style={{ flexGrow: phase.grow }}
                        >
                            {phase.name}
                        </span>
                    ))}
                </div>
                <div className="grid gap-3 sm:grid-cols-2">
                    <div className="border-border/70 bg-background flex flex-col gap-2 rounded-xl border p-4">
                        <span className="eyebrow text-muted-foreground">
                            Brief
                        </span>
                        <span className="text-success flex items-center gap-1.5 text-sm font-medium">
                            <CheckCircle2 className="size-4" />
                            Approved by the client
                        </span>
                        <span className="text-muted-foreground font-mono text-[11px]">
                            Revision 2 · 3 Mar
                        </span>
                    </div>
                    <div className="border-border/70 bg-background flex flex-col gap-2 rounded-xl border p-4">
                        <span className="eyebrow text-muted-foreground">
                            Readiness
                        </span>
                        <span className="font-display text-3xl leading-none font-bold tabular-nums">
                            86%
                        </span>
                        <span className="bg-muted h-1.5 overflow-hidden rounded-full">
                            <span className="bg-primary block h-full w-[86%] rounded-full" />
                        </span>
                    </div>
                </div>
                <ul className="divide-border/70 flex flex-col divide-y text-sm">
                    {[
                        ['Launch day reel', 'scheduled'],
                        ['Launch carousel', 'waiting_client'],
                        ['Behind the scenes', 'internal_review'],
                    ].map(([title, status]) => (
                        <li
                            key={title}
                            className="flex items-center justify-between gap-3 py-2.5"
                        >
                            <span>{title}</span>
                            <StatusPill kind="post" value={status} />
                        </li>
                    ))}
                </ul>
                <div className="bg-ink text-ink-foreground flex items-center justify-between gap-3 rounded-xl px-4 py-3">
                    <span className="flex flex-col">
                        <span className="eyebrow text-cream/60">
                            Wrap report
                        </span>
                        <span className="text-sm">On-time rate</span>
                    </span>
                    <span className="font-display text-lime text-2xl font-bold tabular-nums">
                        96%
                    </span>
                </div>
            </div>
        </section>
    );
}
