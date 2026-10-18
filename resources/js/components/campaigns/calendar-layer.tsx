import { Link } from '@inertiajs/react';
import { useState } from 'react';
import {
    campaignSwatch,
    campaignSwatchText,
} from '@/components/campaigns/campaign-chip';
import { platformIcon } from '@/components/platform-icon';
import { cn } from '@/lib/utils';
import { plan as campaignPlan, show as showCampaign } from '@/routes/campaigns';

export type CalendarBand = {
    id: number;
    name: string;
    code: string | null;
    colour: string | null;
    client_name: string | null;
    start_date: string | null;
    end_date: string | null;
    phases: {
        name: string;
        starts_on: string | null;
        ends_on: string | null;
    }[];
};

export type CalendarGhost = {
    id: number;
    campaign_id: number;
    campaign_code: string | null;
    campaign_colour: string | null;
    title: string;
    platform: string;
    date: string | null;
    time: string | null;
};

export type CampaignLayer = { bands: CalendarBand[]; ghosts: CalendarGhost[] };

const STORAGE_KEY = 'calendar-campaign-layer';
const MAX_BANDS = 3;

/** Step C-3.5 — the layer is on by default and remembered per browser. */
export function useCampaignLayerToggle(): [boolean, (on: boolean) => void] {
    const [on, setOn] = useState(() => {
        try {
            return window.localStorage.getItem(STORAGE_KEY) !== 'off';
        } catch {
            return true;
        }
    });

    return [
        on,
        (next: boolean) => {
            setOn(next);

            try {
                window.localStorage.setItem(STORAGE_KEY, next ? 'on' : 'off');
            } catch {
                // Not remembered in a private window; still works for this visit.
            }
        },
    ];
}

function covers(
    start: string | null,
    end: string | null,
    day: string,
): boolean {
    return start !== null && start <= day && (end === null || end >= day);
}

/**
 * The campaign bands running through one calendar day: a slim bar per campaign in its colour,
 * labelled where the band starts (or at the start of the week). Hovering names the phase that
 * day. Bars line up across the grid's hairline gaps, so a campaign reads as one band.
 */
export function CampaignDayBands({
    bands,
    day,
    labelled,
}: {
    bands: CalendarBand[];
    day: string;
    labelled: boolean;
}) {
    const active = bands.filter((band) =>
        covers(band.start_date, band.end_date, day),
    );

    if (active.length === 0) {
        return null;
    }

    return (
        <div className="-mx-1.5 flex flex-col gap-0.5">
            {active.slice(0, MAX_BANDS).map((band) => {
                const phase = band.phases.find((item) =>
                    covers(item.starts_on, item.ends_on, day),
                );
                const showLabel = labelled || band.start_date === day;

                return (
                    <Link
                        key={band.id}
                        href={showCampaign(band.id)}
                        title={`${band.name}${band.client_name ? ` · ${band.client_name}` : ''}${phase ? ` · ${phase.name}` : ''}`}
                        className={cn(
                            'flex h-4 items-center overflow-hidden px-1.5 text-[9.5px] leading-none font-medium transition-opacity hover:opacity-80',
                            campaignSwatch(band.colour),
                            campaignSwatchText(band.colour),
                            band.start_date === day && 'ml-1.5 rounded-l-full',
                            band.end_date === day && 'mr-1.5 rounded-r-full',
                        )}
                    >
                        {showLabel && (
                            <span className="truncate">
                                {band.code ?? band.name}
                                {phase ? ` · ${phase.name}` : ''}
                            </span>
                        )}
                    </Link>
                );
            })}
            {active.length > MAX_BANDS && (
                <span className="text-muted-foreground px-1.5 text-[10px]">
                    +{active.length - MAX_BANDS} more
                </span>
            )}
        </div>
    );
}

/**
 * A planned deliverable nobody has started, drawn as a dashed "ghost" next to real posts so gaps
 * in the plan are obvious. Clicking it opens the campaign's plan, where "Start post" lives.
 */
export function GhostChip({ ghost }: { ghost: CalendarGhost }) {
    const Icon = platformIcon(ghost.platform);

    return (
        <Link
            href={`${campaignPlan.url(ghost.campaign_id)}#deliverable-${ghost.id}`}
            title={`Planned, not started: ${ghost.title}`}
            className="text-muted-foreground hover:text-foreground hover:border-primary/50 flex min-w-0 flex-col gap-0.5 rounded-lg border border-dashed bg-transparent py-1 pr-2 pl-2 text-xs transition-colors"
        >
            <span className="flex items-center justify-between gap-1.5">
                <span className="font-mono text-[11px] tabular-nums">
                    {ghost.time ?? '—'}
                </span>
                <span className="text-[10.5px] font-medium">Planned</span>
            </span>
            <span className="flex min-w-0 items-center gap-1">
                <Icon className="size-3 shrink-0" />
                <span className="truncate">{ghost.title}</span>
            </span>
        </Link>
    );
}
