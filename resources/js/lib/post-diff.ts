/**
 * Step M-5.3 — renders the server's post-revision diff (`PostRevisionService::diff`) as plain
 * "what changed" lines, shared by the editor's history drawer and the client's "what changed since
 * your last review".
 */

type TextChange = { from: string | null; to: string | null };

export type RevisionDiff = {
    caption: TextChange | null;
    first_comment: TextChange | null;
    link_url: TextChange | null;
    hashtags: { added: string[]; removed: string[] } | null;
    media: { added: number; removed: number; reordered: boolean } | null;
    channels: {
        platform: string | null;
        handle: string | null;
        fields: string[];
    }[];
    is_empty: boolean;
};

const channelFieldLabels: Record<string, string> = {
    caption_override: 'caption',
    hashtags_override: 'hashtags',
    first_comment: 'first comment',
    link_url: 'link',
    format: 'format',
};

export function summarizeDiff(diff: RevisionDiff): string[] {
    const lines: string[] = [];

    if (diff.caption) {
        lines.push('Caption changed');
    }

    if (diff.first_comment) {
        lines.push('First comment changed');
    }

    if (diff.link_url) {
        lines.push('Link changed');
    }

    if (diff.hashtags) {
        const bits: string[] = [];

        if (diff.hashtags.added.length > 0) {
            bits.push(
                `added ${diff.hashtags.added.map((tag) => `#${tag}`).join(', ')}`,
            );
        }

        if (diff.hashtags.removed.length > 0) {
            bits.push(
                `removed ${diff.hashtags.removed.map((tag) => `#${tag}`).join(', ')}`,
            );
        }

        lines.push(`Hashtags — ${bits.join('; ')}`);
    }

    if (diff.media) {
        const bits: string[] = [];

        if (diff.media.added > 0) {
            bits.push(`${diff.media.added} added`);
        }

        if (diff.media.removed > 0) {
            bits.push(`${diff.media.removed} removed`);
        }

        if (diff.media.reordered) {
            bits.push('reordered');
        }

        lines.push(`Media — ${bits.join(', ')}`);
    }

    for (const channel of diff.channels) {
        const name = channel.handle ?? channel.platform ?? 'A channel';

        if (channel.fields.includes('added')) {
            lines.push(`${name} added`);
        } else if (channel.fields.includes('removed')) {
            lines.push(`${name} removed`);
        } else {
            const fields = channel.fields
                .map((field) => channelFieldLabels[field] ?? field)
                .join(', ');
            lines.push(`${name}: ${fields} changed`);
        }
    }

    return lines;
}
