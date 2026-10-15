import type { LucideIcon } from 'lucide-react';
import { Facebook, Instagram, Linkedin, Music2, Share2 } from 'lucide-react';
import { cn } from '@/lib/utils';

const platformIcons: Record<string, LucideIcon> = {
    instagram: Instagram,
    tiktok: Music2,
    facebook: Facebook,
    linkedin: Linkedin,
};

export function platformIcon(platform: string | null | undefined): LucideIcon {
    return (platform && platformIcons[platform]) || Share2;
}

/** A social platform glyph in a small bordered tile — the platform cue used across post rows. */
export function PlatformIcon({
    platform,
    className,
}: {
    platform: string | null | undefined;
    className?: string;
}) {
    const Icon = platformIcon(platform);

    return (
        <span
            className={cn(
                'border-border bg-background text-foreground/70 flex size-9 shrink-0 items-center justify-center rounded-xl border',
                className,
            )}
            title={platform ?? undefined}
        >
            <Icon className="size-4" />
        </span>
    );
}
