<?php

namespace App\Support\Store;

use App\Models\Booking;
use Illuminate\Support\Carbon;

/**
 * Step CG-4.5 — the booking as an iCalendar file, built by hand (RFC 5545 is a handful of lines for
 * a single all-UTC event, and a calendar library for this would be a dependency for nothing).
 *
 * Times go out as UTC (`…Z`), which every calendar client converts to the reader's own zone, so the
 * buyer and the creator never have to agree on a timezone. `UID` is derived from the booking id so
 * a re-sent email updates the same event rather than adding a second one.
 */
final class IcsFile
{
    public static function forBooking(Booking $booking, string $summary): string
    {
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Alima//Creator store//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:REQUEST',
            'BEGIN:VEVENT',
            'UID:booking-'.$booking->id.'@'.(parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'alima'),
            'DTSTAMP:'.self::stamp($booking->created_at ?? Carbon::now()),
            'DTSTART:'.self::stamp($booking->starts_at),
            'DTEND:'.self::stamp($booking->ends_at),
            'SUMMARY:'.self::escape($summary),
            'STATUS:CONFIRMED',
            'END:VEVENT',
            'END:VCALENDAR',
        ];

        // CRLF, per the spec — some clients reject bare newlines.
        return implode("\r\n", $lines)."\r\n";
    }

    private static function stamp(Carbon $moment): string
    {
        return $moment->clone()->utc()->format('Ymd\THis\Z');
    }

    private static function escape(string $value): string
    {
        return str_replace([',', ';', "\n"], ['\\,', '\\;', '\\n'], $value);
    }
}
