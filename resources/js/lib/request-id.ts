/**
 * A v4 UUID per click, sent as `request_id` so a double-submit resolves to the same job/set/variant
 * server-side. `crypto.randomUUID()` needs a secure context, and local `.test` hosts are plain http,
 * so fall back to `getRandomValues` (available everywhere) rather than throwing.
 */
export function requestId(): string {
    if (typeof crypto.randomUUID === 'function' && window.isSecureContext) {
        return crypto.randomUUID();
    }

    const bytes = crypto.getRandomValues(new Uint8Array(16));
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const hex = Array.from(bytes, (byte) =>
        byte.toString(16).padStart(2, '0'),
    ).join('');

    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}
