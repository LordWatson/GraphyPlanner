import { describe, expect, it } from 'vite-plus/test';
import {
    cityOf,
    convertSlot,
    instantToZoned,
    isValidTimeZone,
    offsetMinutes,
    zonedToInstant,
} from '@/lib/timezones';

describe('timezones', () => {
    it('converts a local slot to the instant it means', () => {
        // 09:00 in London during BST is 08:00 UTC; in Dubai (UTC+4) it's 05:00 UTC.
        expect(
            zonedToInstant(
                { date: '2026-07-01', time: '09:00' },
                'Europe/London',
            ),
        ).toBe(Date.UTC(2026, 6, 1, 8, 0));
        expect(
            zonedToInstant({ date: '2026-07-01', time: '09:00' }, 'Asia/Dubai'),
        ).toBe(Date.UTC(2026, 6, 1, 5, 0));
    });

    it('keeps the same instant across zones when asked (spec §4.6 opt-in)', () => {
        expect(
            convertSlot(
                { date: '2026-10-20', time: '09:00' },
                'Europe/London',
                'Asia/Dubai',
            ),
        ).toEqual({ date: '2026-10-20', time: '12:00' });

        // Crossing midnight moves the date too.
        expect(
            convertSlot(
                { date: '2026-10-20', time: '22:30' },
                'Europe/London',
                'Asia/Dubai',
            ),
        ).toEqual({ date: '2026-10-21', time: '01:30' });
    });

    it('follows daylight saving on both sides of the change', () => {
        // London leaves BST on 25 Oct 2026.
        expect(offsetMinutes(Date.UTC(2026, 9, 24, 12), 'Europe/London')).toBe(
            60,
        );
        expect(offsetMinutes(Date.UTC(2026, 9, 26, 12), 'Europe/London')).toBe(
            0,
        );
        expect(
            instantToZoned(Date.UTC(2026, 9, 26, 9, 0), 'Europe/London'),
        ).toEqual({ date: '2026-10-26', time: '09:00' });
    });

    it('resolves a wall time skipped by the spring-forward gap forward', () => {
        // 01:30 on 29 Mar 2026 doesn't exist in London (01:00 → 02:00).
        const instant = zonedToInstant(
            { date: '2026-03-29', time: '01:30' },
            'Europe/London',
        );

        expect(instant).not.toBeNull();
        expect(instantToZoned(instant!, 'Europe/London').time).toBe('02:30');
    });

    it('returns null for incomplete slots or unknown zones', () => {
        expect(zonedToInstant({ date: '', time: '09:00' }, 'UTC')).toBeNull();
        expect(
            zonedToInstant({ date: '2026-10-20', time: '' }, 'UTC'),
        ).toBeNull();
        expect(
            zonedToInstant(
                { date: '2026-10-20', time: '09:00' },
                'Mars/Olympus',
            ),
        ).toBeNull();
        expect(isValidTimeZone('Mars/Olympus')).toBe(false);
        expect(isValidTimeZone('Asia/Dubai')).toBe(true);
    });

    it('names a zone by its city', () => {
        expect(cityOf('America/Argentina/Buenos_Aires')).toBe('Buenos Aires');
        expect(cityOf('UTC')).toBe('UTC');
    });
});
