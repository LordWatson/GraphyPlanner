import { Sparkles } from 'lucide-react';
import type { RevisionDiff } from '@/lib/post-diff';
import { summarizeDiff } from '@/lib/post-diff';

/**
 * Step M-5.3 — "what changed since you last saw it", shown to a client re-reviewing a post (the
 * portal and the review link). Renders nothing on a first review.
 */
export function ReviewChanges({ changes }: { changes: RevisionDiff | null }) {
    if (!changes) {
        return null;
    }

    const lines = summarizeDiff(changes);

    if (lines.length === 0) {
        return null;
    }

    return (
        <section className="border-info/30 bg-info/5 rounded-2xl border p-4">
            <h2 className="flex items-center gap-2 text-sm font-semibold">
                <Sparkles className="text-info size-4" />
                What changed since your last review
            </h2>
            <ul className="mt-2 flex flex-col gap-1 text-sm">
                {lines.map((line, index) => (
                    <li key={index} className="text-foreground/85">
                        {line}
                    </li>
                ))}
            </ul>
        </section>
    );
}
