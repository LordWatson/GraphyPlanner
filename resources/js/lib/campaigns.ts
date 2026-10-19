import { getXsrfTokenFromCookie } from '@/components/clients/client-layout';

export type JsonResult<T> =
    | { ok: true; data: T }
    | { ok: false; message: string };

/**
 * POST to a JSON endpoint as a normal stateful request (same XSRF cookie Inertia uses) — for the
 * campaign AI suggestions, which return data rather than a page. Turns errors into one readable
 * line (rate limit, validation, server).
 */
export async function postJson<T>(
    url: string,
    body: object = {},
): Promise<JsonResult<T>> {
    return requestJson<T>(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-XSRF-TOKEN': getXsrfTokenFromCookie(),
        },
        body: JSON.stringify(body),
    });
}

/** GET a JSON endpoint (e.g. a queued AI run's status), with the same error handling as `postJson`. */
export async function getJson<T>(url: string): Promise<JsonResult<T>> {
    return requestJson<T>(url, { method: 'GET' });
}

async function requestJson<T>(
    url: string,
    init: Omit<RequestInit, 'headers'> & { headers?: Record<string, string> },
): Promise<JsonResult<T>> {
    try {
        const response = await fetch(url, {
            ...init,
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                ...init.headers,
            },
        });

        const payload = (await response.json().catch(() => null)) as
            | (T & { message?: string; errors?: Record<string, string[]> })
            | null;

        if (!response.ok) {
            const firstError = payload?.errors
                ? Object.values(payload.errors)[0]?.[0]
                : undefined;

            // A gateway timeout/error page is HTML, so there's no JSON message to show.
            const fallback = [502, 503, 504].includes(response.status)
                ? 'The server took too long to answer. Try again.'
                : `Something went wrong (${response.status}). Try again.`;

            return {
                ok: false,
                message:
                    response.status === 429
                        ? 'That’s a lot of AI in a short time. Give it a minute.'
                        : (firstError ?? payload?.message ?? fallback),
            };
        }

        return { ok: true, data: payload as T };
    } catch {
        return {
            ok: false,
            message: 'We couldn’t reach the server. Check your connection.',
        };
    }
}

/** The labels shared by the brief editor, its AI draft and the portal's read-only brief. */
export const briefSectionLabels: Record<string, string> = {
    background: 'Background',
    objective_detail: 'Objective',
    audience: 'Audience',
    key_messages: 'Key messages',
    offer: 'Offer',
    call_to_action: 'Call to action',
    mandatories: 'Mandatories',
    avoid: 'Avoid',
    deliverables_summary: 'Deliverables',
    success_measures: 'Success measures',
};
