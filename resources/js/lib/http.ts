import { toast } from 'sonner';

/**
 * Step I-14.2 / M-1.4 — the one place the app talks JSON to its own endpoints outside Inertia's
 * router (notification list, asset library, AI suggestions, uploads, polling). Every call:
 *
 * - is a normal stateful request (session cookie + Laravel's `XSRF-TOKEN`, like Inertia's own);
 * - sends a plain object as JSON, or a `FormData` as multipart (file uploads);
 * - never throws: it resolves `{ ok: true, data }` or `{ ok: false, message, errors, status }`,
 *   with the message already turned into one readable line (validation, rate limit, server,
 *   offline);
 * - toasts the error when `toastOnError` is set, so a call site that has nothing better to do
 *   with a failure doesn't have to.
 *
 * `fetch(` must not appear anywhere else in `resources/js` (checked by `scripts/check-no-raw-fetch.mjs`).
 */

export type HttpErrors = Record<string, string[]>;

export type HttpResult<T> =
    | { ok: true; status: number; data: T }
    | {
          ok: false;
          status: number;
          message: string;
          errors: HttpErrors;
          /** The request was cancelled through `signal` — usually nothing to show. */
          aborted: boolean;
      };

export type HttpOptions = {
    signal?: AbortSignal;
    /** Toast the error message on failure (not on abort). */
    toastOnError?: boolean;
    /** Override the message for a 429 (e.g. the AI endpoints' friendlier line). */
    rateLimitMessage?: string;
    headers?: Record<string, string>;
    /**
     * Let the request outlive the page (a "this happened" ping sent just before the browser hands
     * over to another app). The body must stay small.
     */
    keepalive?: boolean;
};

type Method = 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';

/** Laravel's `XSRF-TOKEN` cookie (readable by design), sent back as `X-XSRF-TOKEN`. */
export function xsrfToken(): string {
    if (typeof document === 'undefined') {
        return '';
    }

    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);

    return match ? decodeURIComponent(match[1]) : '';
}

export async function request<T>(
    method: Method,
    url: string,
    body?: object | FormData,
    options: HttpOptions = {},
): Promise<HttpResult<T>> {
    const headers: Record<string, string> = {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        ...options.headers,
    };

    if (method !== 'GET') {
        headers['X-XSRF-TOKEN'] = xsrfToken();
    }

    let payloadBody: BodyInit | undefined;

    if (body instanceof FormData) {
        payloadBody = body; // the browser sets the multipart boundary
    } else if (body !== undefined && method !== 'GET') {
        headers['Content-Type'] = 'application/json';
        payloadBody = JSON.stringify(body);
    }

    let result: HttpResult<T>;

    try {
        const response = await fetch(url, {
            method,
            headers,
            body: payloadBody,
            credentials: 'same-origin',
            signal: options.signal,
            keepalive: options.keepalive,
        });

        const payload = (await response.json().catch(() => null)) as
            | (T & { message?: string; errors?: HttpErrors })
            | null;

        result = response.ok
            ? { ok: true, status: response.status, data: payload as T }
            : {
                  ok: false,
                  status: response.status,
                  message: errorMessage(response.status, payload, options),
                  errors: payload?.errors ?? {},
                  aborted: false,
              };
    } catch (error) {
        const aborted =
            error instanceof DOMException && error.name === 'AbortError';

        result = {
            ok: false,
            status: 0,
            message: aborted
                ? 'Cancelled.'
                : 'We couldn’t reach the server. Check your connection.',
            errors: {},
            aborted,
        };
    }

    if (!result.ok && !result.aborted && options.toastOnError) {
        toast.error(result.message);
    }

    return result;
}

function errorMessage(
    status: number,
    payload: { message?: string; errors?: HttpErrors } | null,
    options: HttpOptions,
): string {
    if (status === 429) {
        return (
            options.rateLimitMessage ??
            'That’s a lot of requests in a short time. Give it a minute.'
        );
    }

    // The web server rejects oversized bodies with an HTML page before Laravel runs.
    if (status === 413) {
        return 'This file is larger than the server accepts. Ask an admin to raise the upload limit.';
    }

    if (status === 419) {
        return 'Your session expired. Refresh the page and try again.';
    }

    const firstError = payload?.errors
        ? Object.values(payload.errors)[0]?.[0]
        : undefined;

    if (firstError) {
        return firstError;
    }

    // Endpoints answer with a readable `message` (e.g. "AI is switched off"), so show it.
    if (payload?.message) {
        return payload.message;
    }

    // A gateway timeout/error page is HTML, so there's no JSON message to show.
    return [502, 503, 504].includes(status)
        ? 'The server took too long to answer. Try again.'
        : `Something went wrong (${status}). Try again.`;
}

export type BlobResult =
    | { ok: true; blob: Blob }
    | { ok: false; status: number; aborted: boolean };

/**
 * Download a same-origin file into memory, reporting progress (0–1) when the server says how big
 * it is. For the few places that need the bytes themselves rather than a URL — the Story handoff
 * page hands a `File` to the phone's share sheet. Never throws.
 */
export async function fetchBlob(
    url: string,
    options: {
        signal?: AbortSignal;
        onProgress?: (fraction: number) => void;
    } = {},
): Promise<BlobResult> {
    try {
        const response = await fetch(url, {
            credentials: 'same-origin',
            signal: options.signal,
        });

        if (!response.ok) {
            return { ok: false, status: response.status, aborted: false };
        }

        const total = Number(response.headers.get('Content-Length') ?? 0);
        const type = response.headers.get('Content-Type') ?? '';

        if (!response.body || !options.onProgress || total <= 0) {
            return { ok: true, blob: await response.blob() };
        }

        const reader = response.body.getReader();
        const chunks: BlobPart[] = [];
        let received = 0;

        for (;;) {
            const { done, value } = await reader.read();

            if (done) {
                break;
            }

            chunks.push(value);
            received += value.byteLength;
            options.onProgress(Math.min(1, received / total));
        }

        return { ok: true, blob: new Blob(chunks, { type }) };
    } catch (error) {
        return {
            ok: false,
            status: 0,
            aborted:
                error instanceof DOMException && error.name === 'AbortError',
        };
    }
}

/** Laravel's `{ field: [messages] }` → `{ field: first message }`, for `InputError`. */
export function firstErrors(errors: HttpErrors): Record<string, string> {
    return Object.fromEntries(
        Object.entries(errors).map(([key, messages]) => [key, messages[0]]),
    );
}

export const http = {
    get: <T>(url: string, options?: HttpOptions) =>
        request<T>('GET', url, undefined, options),
    post: <T>(url: string, body?: object | FormData, options?: HttpOptions) =>
        request<T>('POST', url, body ?? {}, options),
    put: <T>(url: string, body?: object | FormData, options?: HttpOptions) =>
        request<T>('PUT', url, body ?? {}, options),
    patch: <T>(url: string, body?: object | FormData, options?: HttpOptions) =>
        request<T>('PATCH', url, body ?? {}, options),
    delete: <T>(url: string, body?: object, options?: HttpOptions) =>
        request<T>('DELETE', url, body, options),
};
