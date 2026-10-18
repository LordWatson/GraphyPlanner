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
    try {
        const response = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': getXsrfTokenFromCookie(),
            },
            body: JSON.stringify(body),
        });

        const payload = (await response.json().catch(() => null)) as
            | (T & { message?: string; errors?: Record<string, string[]> })
            | null;

        if (!response.ok) {
            const firstError = payload?.errors
                ? Object.values(payload.errors)[0]?.[0]
                : undefined;

            return {
                ok: false,
                message:
                    response.status === 429
                        ? 'That’s a lot of AI in a short time. Give it a minute.'
                        : (firstError ??
                          payload?.message ??
                          'Something went wrong. Try again.'),
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
