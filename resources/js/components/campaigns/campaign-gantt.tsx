import { Link } from '@inertiajs/react';
import { useMemo } from 'react';
import {
    campaignSwatch,
    campaignSwatchText,
} from '@/components/campaigns/campaign-chip';
import type { CampaignChipData } from '@/components/campaigns/types';
import { formatDateRange } from '@/lib/dates';
import { cn } from '@/lib/utils';
import { show as showCampaign } from '@/routes/campaigns';

export type GanttCampaign = CampaignChipData & {
    status: string;
    start_date: string | null;
    end_date: string | null;
    client: { id: number; name: string | null };
};

const DAY = 86_400_000;
const PAST_DAYS = 14;
const FUTURE_DAYS = 90;

function parse(date: string): number {
    const [y, m, d] = date.split('-').map(Number);

    return new Date(y, m - 1, d).getTime();
}

const monthLabel = new Intl.DateTimeFormat('en-GB', { month: 'short' });

/**
 * Step C-1.1 — the hub's timeline: two weeks back to 90 days ahead, one row per campaign, a band
 * in the campaign's colour across its dates (open-ended ones fade out at the edge), month ticks
 * and a today line. Scrolls horizontally on narrow screens rather than crushing.
 */
export function CampaignGantt({
    campaigns,
    today,
}: {
    campaigns: GanttCampaign[];
    today: string;
}) {
    const start = parse(today) - PAST_DAYS * DAY;
    const end = parse(today) + FUTURE_DAYS * DAY;
    const span = end - start;
    const pct = (time: number) =>
        `${Math.min(100, Math.max(0, ((time - start) / span) * 100))}%`;

    const months = useMemo(() => {
        const ticks: { label: string; at: number }[] = [];
        const cursor = new Date(start);
        cursor.setDate(1);
        cursor.setMonth(cursor.getMonth() + 1);

        while (cursor.getTime() < end) {
            ticks.push({
                label: monthLabel.format(cursor),
                at: cursor.getTime(),
            });
            cursor.setMonth(cursor.getMonth() + 1);
        }

        return ticks;
    }, [start, end]);

    return (
        <div className="border-border bg-card overflow-x-auto rounded-2xl border shadow-sm">
            <div className="relative min-w-[760px]">
                <div className="border-border relative ml-56 h-9 border-b">
                    {months.map((month) => (
                        <span
                            key={month.at}
                            className="text-muted-foreground border-border absolute top-0 h-full border-l border-dashed pt-2.5 pl-1.5 font-mono text-[11px] uppercase"
                            style={{ left: pct(month.at) }}
                        >
                            {month.label}
                        </span>
                    ))}
                </div>
                <ol className="divide-border/60 relative divide-y">
                    {campaigns.map((campaign) => {
                        const from = campaign.start_date
                            ? parse(campaign.start_date)
                            : null;
                        const to = campaign.end_date
                            ? parse(campaign.end_date) + DAY
                            : null;

                        return (
                            <li
                                key={campaign.id}
                                className="hover:bg-muted/40 flex h-12 items-center"
                            >
                                <Link
                                    href={showCampaign(campaign.id)}
                                    className="flex w-56 shrink-0 flex-col justify-center px-4"
                                >
                                    <span className="hover:text-primary truncate text-sm font-medium">
                                        {campaign.name}
                                    </span>
                                    <span className="text-muted-foreground truncate text-[11px]">
                                        {campaign.client.name}
                                    </span>
                                </Link>
                                <div className="relative h-full flex-1">
                                    {from !== null && (
                                        <Link
                                            href={showCampaign(campaign.id)}
                                            title={`${campaign.name} · ${formatDateRange(campaign.start_date, campaign.end_date)}`}
                                            className={cn(
                                                'absolute top-1/2 flex h-7 -translate-y-1/2 items-center overflow-hidden rounded-full px-2.5 text-[11px] font-medium shadow-sm',
                                                campaignSwatch(campaign.colour),
                                                campaignSwatchText(
                                                    campaign.colour,
                                                ),
                                                to === null &&
                                                    '[mask-image:linear-gradient(to_right,black_75%,transparent)]',
                                                campaign.status === 'paused' &&
                                                    'opacity-60',
                                            )}
                                            style={{
                                                left: pct(from),
                                                width: `calc(${pct(to ?? end)} - ${pct(from)})`,
                                                minWidth: '1.75rem',
                                            }}
                                        >
                                            <span className="truncate">
                                                {campaign.code ?? campaign.name}
                                            </span>
                                        </Link>
                                    )}
                                </div>
                            </li>
                        );
                    })}
                </ol>
                <div
                    aria-hidden
                    className="pointer-events-none absolute top-0 right-0 bottom-0 left-56"
                >
                    <div
                        className="bg-primary absolute top-0 bottom-0 w-0.5 rounded-full"
                        style={{ left: pct(parse(today)) }}
                    >
                        <span className="bg-primary text-primary-foreground absolute top-1 left-1 rounded-full px-1.5 font-mono text-[10px] uppercase">
                            Today
                        </span>
                    </div>
                </div>
                <span className="sr-only">
                    Today is marked by a vertical line.
                </span>
            </div>
        </div>
    );
}
