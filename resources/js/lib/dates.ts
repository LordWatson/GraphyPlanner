/**
 * Human dates for the UI. The API sends calendar dates as `YYYY-MM-DD` (or ISO timestamps); never
 * render those raw. Parsed as local calendar dates so a date never shifts a day across timezones.
 */
function parseCalendarDate(value: string): Date {
    const [year, month, day] = value.slice(0, 10).split('-').map(Number);

    return new Date(year, month - 1, day);
}

/** "2026-10-07" → "7 Oct 2026". `fallback` for a missing date. */
export function formatDate(
    value: string | null | undefined,
    fallback = '—',
): string {
    if (!value) {
        return fallback;
    }

    return parseCalendarDate(value).toLocaleDateString('en-GB', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}

/**
 * "7 Oct → 14 Nov 2026" (year shown once when both ends share it). Missing ends read "TBC".
 */
export function formatDateRange(
    start: string | null | undefined,
    end: string | null | undefined,
): string {
    if (!start && !end) {
        return 'Dates TBC';
    }

    if (start && end && start.slice(0, 4) === end.slice(0, 4)) {
        const startLabel = parseCalendarDate(start).toLocaleDateString(
            'en-GB',
            { day: 'numeric', month: 'short' },
        );

        return `${startLabel} → ${formatDate(end)}`;
    }

    return `${formatDate(start, 'TBC')} → ${formatDate(end, 'TBC')}`;
}

/**
 * A moment in time — ISO-8601 (`2026-10-16T14:05:00+00:00`) or `Y-m-d H:i:s` — in the viewer's
 * local time: "16 Oct 2026, 14:05".
 */
export function formatDateTime(
    value: string | null | undefined,
    fallback = '—',
): string {
    if (!value) {
        return fallback;
    }

    const date = new Date(
        value.includes('T') ? value : value.replace(' ', 'T'),
    );

    if (Number.isNaN(date.getTime())) {
        return value;
    }

    return date.toLocaleString('en-GB', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

/**
 * A channel's scheduled slot, in that channel's own local time:
 * "2026-10-17" + "09:00:00" → "Sat 17 Oct · 09:00".
 */
export function formatSchedule(
    date: string | null | undefined,
    time: string | null | undefined,
    fallback = 'Not scheduled',
): string {
    if (!date) {
        return fallback;
    }

    const day = parseCalendarDate(date).toLocaleDateString('en-GB', {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
    });

    return time ? `${day} · ${time.slice(0, 5)}` : day;
}
