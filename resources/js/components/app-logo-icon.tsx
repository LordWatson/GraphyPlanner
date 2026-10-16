import type { SVGAttributes } from 'react';
import { ArtworkSvg } from '@/components/brand-mark';
import { markArtwork } from '@/components/brand/artwork';

/** The Alima panda mark, single-colour (inherits `currentColor`). Prefer `BrandMark`. */
export default function AppLogoIcon(props: SVGAttributes<SVGSVGElement>) {
    return <ArtworkSvg artwork={markArtwork} {...props} />;
}
