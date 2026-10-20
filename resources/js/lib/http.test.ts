import { toast } from 'sonner';
import {
    afterEach,
    beforeEach,
    describe,
    expect,
    it,
    vi,
} from 'vite-plus/test';
import { firstErrors, http } from '@/lib/http';

vi.mock('sonner', () => ({ toast: { error: vi.fn() } }));

function respond(status: number, body: unknown) {
    return vi.fn(
        async () =>
            new Response(body === undefined ? 'oops' : JSON.stringify(body), {
                status,
                headers: { 'Content-Type': 'application/json' },
            }),
    );
}

describe('http', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        document.cookie = 'XSRF-TOKEN=token%3D123';
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('sends JSON with the CSRF token and returns the data', async () => {
        const fetchMock = respond(200, { url: 'https://x.test' });
        vi.stubGlobal('fetch', fetchMock);

        const result = await http.post<{ url: string }>('/things', { a: 1 });

        expect(result).toEqual({
            ok: true,
            status: 200,
            data: { url: 'https://x.test' },
        });
        const [url, init] = fetchMock.mock.calls[0] as unknown as [
            string,
            RequestInit,
        ];
        const headers = init.headers as Record<string, string>;
        expect(url).toBe('/things');
        expect(init.method).toBe('POST');
        expect(init.body).toBe('{"a":1}');
        expect(init.credentials).toBe('same-origin');
        expect(headers['X-XSRF-TOKEN']).toBe('token=123');
        expect(headers['Content-Type']).toBe('application/json');
    });

    it('sends FormData as-is (multipart) and never sets a JSON content type', async () => {
        const fetchMock = respond(201, { asset: { id: 4 } });
        vi.stubGlobal('fetch', fetchMock);
        const body = new FormData();
        body.append('file', new Blob(['x']), 'x.png');

        await http.post('/assets', body);

        const [, init] = fetchMock.mock.calls[0] as unknown as [
            string,
            RequestInit,
        ];
        expect(init.body).toBe(body);
        expect(
            (init.headers as Record<string, string>)['Content-Type'],
        ).toBeUndefined();
    });

    it('does not send the CSRF header on GET', async () => {
        const fetchMock = respond(200, {});
        vi.stubGlobal('fetch', fetchMock);

        await http.get('/list');

        const [, init] = fetchMock.mock.calls[0] as unknown as [
            string,
            RequestInit,
        ];
        expect(
            (init.headers as Record<string, string>)['X-XSRF-TOKEN'],
        ).toBeUndefined();
        expect(init.body).toBeUndefined();
    });

    it('turns validation errors into the first message, with every field', async () => {
        vi.stubGlobal(
            'fetch',
            respond(422, {
                message: 'The given data was invalid.',
                errors: { email: ['Use a real address.'], days: ['Too many.'] },
            }),
        );

        const result = await http.post('/invites', {});

        expect(result.ok).toBe(false);

        if (!result.ok) {
            expect(result.message).toBe('Use a real address.');
            expect(firstErrors(result.errors)).toEqual({
                email: 'Use a real address.',
                days: 'Too many.',
            });
        }
    });

    it.each([
        [429, 'That’s a lot of requests in a short time. Give it a minute.'],
        [419, 'Your session expired. Refresh the page and try again.'],
        [
            413,
            'This file is larger than the server accepts. Ask an admin to raise the upload limit.',
        ],
        [504, 'The server took too long to answer. Try again.'],
    ])('explains a %i in plain words', async (status, message) => {
        vi.stubGlobal('fetch', respond(status, undefined));

        const result = await http.get('/x');

        expect(result.ok ? null : result.message).toBe(message);
    });

    it('uses a custom rate-limit message and toasts errors when asked', async () => {
        vi.stubGlobal('fetch', respond(429, {}));

        await http.post(
            '/ai',
            {},
            { rateLimitMessage: 'Slow down.', toastOnError: true },
        );

        expect(toast.error).toHaveBeenCalledWith('Slow down.');
    });

    it('reports offline and aborted requests without throwing', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(async () => Promise.reject(new TypeError('Failed to fetch'))),
        );
        const offline = await http.get('/x', { toastOnError: true });
        expect(offline).toMatchObject({ ok: false, status: 0, aborted: false });
        expect(toast.error).toHaveBeenCalledTimes(1);

        vi.stubGlobal(
            'fetch',
            vi.fn(async () =>
                Promise.reject(new DOMException('gone', 'AbortError')),
            ),
        );
        const aborted = await http.get('/x', { toastOnError: true });
        expect(aborted).toMatchObject({ ok: false, aborted: true });
        // An abort is the caller's own doing: no toast.
        expect(toast.error).toHaveBeenCalledTimes(1);
    });
});
