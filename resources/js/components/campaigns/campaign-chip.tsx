import type { CampaignChipData } from '@/components/campaigns/types';
import { cn } from '@/lib/utils';

/**
 * Campaign identity colours (`App\Enums\CampaignColour`) — brand token names only, never hex, so
 * dark mode keeps working. Identity, never status. Signal Orange is deliberately absent.
 */
export const campaignColourClass: Record<string, string> = {
    teal: 'bg-teal dark:ring-1 dark:ring-inset dark:ring-cream/50',
    electric: 'bg-electric',
    lilac: 'bg-lilac',
    lime: 'bg-lime',
    ink: 'bg-ink dark:bg-cream',
};

export function campaignSwatch(colour: string | null | undefined): string {
    return (colour && campaignColourClass[colour]) || 'bg-muted-foreground/40';
}

/**
 * Type that may sit on a swatch fill (book §3.3 approved pairs): cream on teal and ink, ink on the
 * light accents. Use for calendar/timeline bands that carry a label.
 */
export function campaignSwatchText(colour: string | null | undefined): string {
    if (colour === 'teal') {
        return 'text-cream';
    }

    // Ink flips to cream in dark mode, so its type flips too.
    return colour === 'ink' ? 'text-cream dark:text-ink' : 'text-ink';
}

/** The small square swatch in a campaign's colour. */
export function CampaignSwatch({
    colour,
    className,
}: {
    colour: string | null | undefined;
    className?: string;
}) {
    return (
        <span
            aria-hidden
            className={cn(
                'size-2.5 shrink-0 rounded-[3px]',
                campaignSwatch(colour),
                className,
            )}
        />
    );
}

/**
 * Step C-1.3 — the campaign chip shown wherever a post appears: the colour swatch, the mono code
 * (`SPR26`) and, unless `compact`, the name. Text is always present, so colour never carries the
 * meaning alone.
 */
export function CampaignChip({
    campaign,
    compact = false,
    className,
}: {
    campaign: CampaignChipData;
    compact?: boolean;
    className?: string;
}) {
    return (
        <span
            className={cn(
                'border-border bg-card text-foreground/85 inline-flex max-w-full min-w-0 items-center gap-1.5 rounded-full border px-2 py-0.5 text-[11px] leading-4',
                className,
            )}
            title={campaign.name}
        >
            <CampaignSwatch colour={campaign.colour} />
            {campaign.code && (
                <span className="font-mono tracking-[0.06em]">
                    {campaign.code}
                </span>
            )}
            {(!compact || !campaign.code) && (
                <span className="truncate">{campaign.name}</span>
            )}
        </span>
    );
}
