import type { SVGAttributes } from 'react';

/**
 * AlimaPlanner's mark: a 2×2 planner grid where one cell has bloomed into a quarter-circle — the
 * calendar and the graph in one geometric glyph. Single-colour; inherits `fill-current`.
 */
export default function AppLogoIcon(props: SVGAttributes<SVGElement>) {
    return (
        <svg {...props} viewBox="0 0 32 32" xmlns="http://www.w3.org/2000/svg">
            <rect x="2" y="2" width="12" height="12" rx="3" />
            <path d="M18 14V2a12 12 0 0 1 12 12H18Z" />
            <rect x="2" y="18" width="12" height="12" rx="3" />
            <rect x="18" y="18" width="12" height="12" rx="6" />
        </svg>
    );
}
