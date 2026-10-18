/**
 * Step I-1.1 — keeps preview visitors inside the read-only `/preview/*` mirror.
 *
 * The preview pages reuse the real app's components, so their links and forms point at the
 * real (non-preview) URLs. Rather than branching every page, the preview layout installs one
 * router guard (`usePreviewGuard`) that rewrites mirrored GET visits to their `/preview/*`
 * twin and cancels everything else (writes, and pages with no mirror) with a toast.
 *
 * Keep `MIRRORED_PATHS` in sync with the `preview.*` GET routes in `routes/web.php`.
 */
const MIRRORED_PATHS: RegExp[] = [
    /^\/(dashboard|calendar|home)$/,
    /^\/clients(\/create)?$/,
    /^\/clients\/\d+(\/(edit|brand-brain|assets|analytics|posts|media|campaigns|billing|portal-access|ai|briefs))?$/,
    /^\/invoices(\/export)?$/,
    /^\/invoices\/\d+(\/edit)?$/,
    /^\/campaigns$/,
    /^\/campaigns\/\d+(\/(edit|brief|plan|board|assets|approvals|results|activity))?$/,
    /^\/posts\/\d+\/(edit|music-search)$/,
    /^\/analytics(\/(export|pdf|reports))?$/,
    /^\/analytics\/reports\/\d+(\/(export|pdf))?$/,
];

/** Public pages a preview visitor may leave to (no app data behind them). */
const PUBLIC_PATHS: RegExp[] = [
    /^\/$/,
    /^\/about$/,
    /^\/login$/,
    /^\/register-organization$/,
];

export type PreviewDecision =
    | { action: 'allow' }
    | { action: 'redirect'; url: string }
    | { action: 'block'; reason: string };

export function isPreviewPath(pathname: string): boolean {
    return pathname === '/preview' || pathname.startsWith('/preview/');
}

/** The `/preview/*` twin of a real app URL, or `null` when the page has no preview mirror. */
export function toPreviewUrl(url: URL): string | null {
    if (isPreviewPath(url.pathname)) {
        return url.pathname + url.search + url.hash;
    }

    if (!MIRRORED_PATHS.some((pattern) => pattern.test(url.pathname))) {
        return null;
    }

    return `/preview${url.pathname}${url.search}${url.hash}`;
}

export function decidePreviewVisit(
    url: URL,
    method: string,
    origin: string = window.location.origin,
): PreviewDecision {
    if (url.origin !== origin) {
        return { action: 'allow' };
    }

    if (method.toLowerCase() !== 'get') {
        return {
            action: 'block',
            reason: 'Preview mode is read-only — nothing can be saved here.',
        };
    }

    if (isPreviewPath(url.pathname)) {
        return { action: 'allow' };
    }

    const previewUrl = toPreviewUrl(url);

    if (previewUrl !== null) {
        return { action: 'redirect', url: previewUrl };
    }

    if (PUBLIC_PATHS.some((pattern) => pattern.test(url.pathname))) {
        return { action: 'allow' };
    }

    return {
        action: 'block',
        reason: 'That page isn’t part of the preview.',
    };
}
