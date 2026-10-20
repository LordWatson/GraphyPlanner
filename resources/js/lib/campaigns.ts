import { http } from '@/lib/http';

/** The slice of `HttpResult` the campaign AI flows use (they also build their own results). */
export type JsonResult<T> =
    | { ok: true; data: T }
    | { ok: false; message: string };

const AI_RATE_LIMIT = 'That’s a lot of AI in a short time. Give it a minute.';

/**
 * POST to a JSON endpoint — for the campaign AI suggestions, which return data rather than a
 * page. A thin wrapper over `http` (Step M-1.4) with the AI endpoints' rate-limit wording.
 */
export function postJson<T>(
    url: string,
    body: object = {},
): Promise<JsonResult<T>> {
    return http.post<T>(url, body, { rateLimitMessage: AI_RATE_LIMIT });
}

/** GET a JSON endpoint (e.g. a queued AI run's status), with the same error handling as `postJson`. */
export function getJson<T>(url: string): Promise<JsonResult<T>> {
    return http.get<T>(url, { rateLimitMessage: AI_RATE_LIMIT });
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
