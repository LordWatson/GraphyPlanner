import { readFileSync } from 'node:fs';
import { logPath } from './env.mjs';

/**
 * The app runs with `MAIL_MAILER=log` and a JSON log channel, so every email is one JSON log
 * line whose message is the raw MIME text. Returns the newest email to `to` whose body contains
 * `marker`.
 */
export function latestEmail(to: string, marker: string): string | null {
    let lines: string[];

    try {
        lines = readFileSync(logPath, 'utf8').trim().split('\n');
    } catch {
        return null;
    }

    for (const line of lines.reverse()) {
        let message: string;

        try {
            message = (JSON.parse(line) as { message: string }).message;
        } catch {
            continue;
        }

        // Laravel's log mailer writes the bodies already decoded (the part headers still say
        // quoted-printable), so the text can be searched as-is.
        if (message.includes(to) && message.includes(marker)) {
            return message;
        }
    }

    return null;
}

/** The first link in an email whose URL contains `fragment`. */
export function linkIn(email: string, fragment: string): string | null {
    const match = email.match(
        new RegExp(`https?://[^\\s"'<>]*${fragment}[^\\s"'<>]*`),
    );

    return match ? match[0].replaceAll('&amp;', '&') : null;
}
