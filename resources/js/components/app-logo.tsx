import { ArtworkSvg, BrandMark } from '@/components/brand-mark';
import { markArtwork } from '@/components/brand/artwork';

/** Sidebar lock-up: Cream on Teal (colourway 03); collapses to the mark alone. */
export default function AppLogo() {
    return (
        <>
            <BrandMark
                size="sm"
                wordmark
                caption="Planner"
                className="text-sidebar-accent-foreground group-data-[collapsible=icon]:hidden"
            />
            <ArtworkSvg
                artwork={markArtwork}
                title="Alima"
                className="text-sidebar-accent-foreground hidden w-8 group-data-[collapsible=icon]:block"
            />
        </>
    );
}
