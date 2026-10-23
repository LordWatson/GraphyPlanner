import aiTextJobs from '@/routes/ai-text-jobs';
import { http } from '@/lib/http';
import { requestId as newRequestId } from '@/lib/request-id';

/**
 * Step M-7.2 — dispatch an async AI text job (captions/trends/ideas) and poll it to completion.
 *
 * The POST returns a `request_id` immediately (no gateway timeout on a slow vendor); this then polls
 * the status endpoint until the job settles and resolves with the result payload, or a readable
 * message on failure/cancel. Pass an `AbortSignal` to stop polling (e.g. the user navigated away);
 * `onQueued` hands back the request id so the caller can offer a Cancel button.
 */
export type AiTextJobResult<T> =
    | { ok: true; result: T }
    | { ok: false; message: string; cancelled: boolean };

const POLL_MS = 1500;
const MAX_POLLS = 80; // ~2 minutes, matching the job timeout.

function delay(ms: number, signal?: AbortSignal): Promise<void> {
    return new Promise((resolve, reject) => {
        const id = setTimeout(resolve, ms);
        signal?.addEventListener(
            'abort',
            () => {
                clearTimeout(id);
                reject(new DOMException('Aborted', 'AbortError'));
            },
            { once: true },
        );
    });
}

export async function runAiTextJob<T>(
    dispatchUrl: string,
    body: Record<string, unknown>,
    options: {
        signal?: AbortSignal;
        onQueued?: (requestId: string) => void;
        rateLimitMessage?: string;
    } = {},
): Promise<AiTextJobResult<T>> {
    const requestId = newRequestId();

    const dispatched = await http.post<{ request_id: string; status: string }>(
        dispatchUrl,
        { ...body, request_id: requestId },
        {
            signal: options.signal,
            rateLimitMessage: options.rateLimitMessage,
        },
    );

    if (!dispatched.ok) {
        return {
            ok: false,
            message: dispatched.message,
            cancelled: dispatched.aborted,
        };
    }

    const id = dispatched.data.request_id ?? requestId;
    options.onQueued?.(id);

    for (let poll = 0; poll < MAX_POLLS; poll++) {
        try {
            await delay(POLL_MS, options.signal);
        } catch {
            return { ok: false, message: 'Cancelled.', cancelled: true };
        }

        const status = await http.get<{
            status: string;
            result: T | null;
            error: string | null;
        }>(aiTextJobs.status.url(id), { signal: options.signal });

        if (!status.ok) {
            if (status.aborted) {
                return { ok: false, message: 'Cancelled.', cancelled: true };
            }
            // A transient poll error: keep trying until MAX_POLLS.
            continue;
        }

        if (status.data.status === 'completed' && status.data.result) {
            return { ok: true, result: status.data.result };
        }

        if (status.data.status === 'failed') {
            return {
                ok: false,
                message:
                    status.data.error ??
                    'AI couldn’t finish that just now. Please try again.',
                cancelled: false,
            };
        }

        if (status.data.status === 'cancelled') {
            return { ok: false, message: 'Cancelled.', cancelled: true };
        }
    }

    return {
        ok: false,
        message: 'That took longer than expected. Please try again.',
        cancelled: false,
    };
}

/** Best-effort cancel of a queued/running job. */
export function cancelAiTextJob(requestId: string): void {
    void http.post(aiTextJobs.cancel.url(requestId));
}
