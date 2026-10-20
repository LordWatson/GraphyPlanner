/**
 * Step I-4.6 / M-1.6 — wall-clock ↔ instant conversion in named IANA timezones, with only `Intl`
 * (no date library). A channel's schedule is stored as its own local date + time (spec §4.6);
 * these helpers only *show* what that means elsewhere, or — when the person explicitly asks for
 * "same instant" — work out each other channel's local time for one shared moment.
 */

export type LocalSlot = { date: string; time: string };

const formatters = new Map<string, Intl.DateTimeFormat>();

function partsFormatter(timeZone: string): Intl.DateTimeFormat {
    let formatter = formatters.get(timeZone);

    if (!formatter) {
        formatter = new Intl.DateTimeFormat('en-US', {
            timeZone,
            hourCycle: 'h23',
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
        });
        formatters.set(timeZone, formatter);
    }

    return formatter;
}

/** The wall-clock fields of an instant in `timeZone`. */
function wallClock(instant: number, timeZone: string) {
    const parts = Object.fromEntries(
        partsFormatter(timeZone)
            .formatToParts(instant)
            .map((part) => [part.type, part.value]),
    );

    return {
        year: Number(parts.year),
        month: Number(parts.month),
        day: Number(parts.day),
        hour: Number(parts.hour) % 24,
        minute: Number(parts.minute),
        second: Number(parts.second),
    };
}

/** Minutes `timeZone` is ahead of UTC at `instant` (e.g. +240 for Asia/Dubai). */
export function offsetMinutes(instant: number, timeZone: string): number {
    const wall = wallClock(instant, timeZone);
    const asUtc = Date.UTC(
        wall.year,
        wall.month - 1,
        wall.day,
        wall.hour,
        wall.minute,
        wall.second,
    );

    return Math.round((asUtc - Math.floor(instant / 1000) * 1000) / 60000);
}

export function isValidTimeZone(timeZone: string | null | undefined): boolean {
    if (!timeZone) {
        return false;
    }

    try {
        partsFormatter(timeZone);

        return true;
    } catch {
        return false;
    }
}

/**
 * A local `YYYY-MM-DD` + `HH:MM` in `timeZone` → the instant (UTC ms), or null when incomplete.
 * Wall times skipped by a DST jump resolve forward, like PHP/Carbon do.
 */
export function zonedToInstant(
    slot: LocalSlot,
    timeZone: string,
): number | null {
    const date = /^(\d{4})-(\d{2})-(\d{2})$/.exec(slot.date);
    const time = /^(\d{2}):(\d{2})/.exec(slot.time);

    if (!date || !time || !isValidTimeZone(timeZone)) {
        return null;
    }

    const guess = Date.UTC(
        Number(date[1]),
        Number(date[2]) - 1,
        Number(date[3]),
        Number(time[1]),
        Number(time[2]),
    );
    const first = guess - offsetMinutes(guess, timeZone) * 60000;
    const second = guess - offsetMinutes(first, timeZone) * 60000;

    return first === second ? first : Math.max(first, second);
}

/** An instant → its local `YYYY-MM-DD` + `HH:MM` in `timeZone`. */
export function instantToZoned(instant: number, timeZone: string): LocalSlot {
    const wall = wallClock(instant, timeZone);
    const pad = (value: number) => String(value).padStart(2, '0');

    return {
        date: `${wall.year}-${pad(wall.month)}-${pad(wall.day)}`,
        time: `${pad(wall.hour)}:${pad(wall.minute)}`,
    };
}

/** The same moment as `slot` in `fromZone`, as a local slot in `toZone`. */
export function convertSlot(
    slot: LocalSlot,
    fromZone: string,
    toZone: string,
): LocalSlot | null {
    const instant = zonedToInstant(slot, fromZone);

    return instant === null ? null : instantToZoned(instant, toZone);
}

/** The viewer's own timezone (the browser's). */
export function viewerTimeZone(): string {
    return Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';
}

/** "Tue 20 Oct, 14:00" for an instant, in `timeZone`. */
export function formatInstant(instant: number, timeZone: string): string {
    return new Intl.DateTimeFormat('en-GB', {
        timeZone,
        weekday: 'short',
        day: 'numeric',
        month: 'short',
        hour: '2-digit',
        minute: '2-digit',
        hourCycle: 'h23',
    }).format(instant);
}

/** "Europe/London" → "London"; "America/Argentina/Buenos_Aires" → "Buenos Aires". */
export function cityOf(timeZone: string): string {
    return (timeZone.split('/').pop() ?? timeZone).replaceAll('_', ' ');
}
