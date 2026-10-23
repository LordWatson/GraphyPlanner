import { useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import type { FeedbackThread } from './types';

/** Absolute character offset of a (node, offset) selection point within `root`'s text. */
function offsetWithin(root: HTMLElement, node: Node, offset: number): number {
    const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
    let total = 0;
    let current = walker.nextNode();
    while (current) {
        if (current === node) {
            return total + offset;
        }
        total += current.textContent?.length ?? 0;
        current = walker.nextNode();
    }
    return total;
}

type Segment = { text: string; thread: FeedbackThread | null };

/**
 * Step M-6.4 — the caption with its range-anchored feedback highlighted, and select-to-comment.
 * Selecting any run of the caption offers "Comment on selection" / "Suggest an edit", capturing the
 * exact character range and the quoted text.
 */
export function CaptionAnnotator({
    caption,
    threads,
    canComment,
    selectedId,
    onSelectRange,
    onSelectThread,
}: {
    caption: string;
    threads: FeedbackThread[];
    canComment: boolean;
    selectedId: number | null;
    onSelectRange: (start: number, end: number, quote: string) => void;
    onSelectThread: (id: number) => void;
}) {
    const ref = useRef<HTMLParagraphElement>(null);
    const [selection, setSelection] = useState<{
        start: number;
        end: number;
        quote: string;
    } | null>(null);

    // Build non-overlapping segments from the caption-range threads, in order.
    const ranges = threads
        .filter(
            (t) =>
                t.anchor_type === 'caption' &&
                typeof t.anchor?.start === 'number' &&
                typeof t.anchor?.end === 'number',
        )
        .sort((a, b) => a.anchor!.start! - b.anchor!.start!);

    const segments: Segment[] = [];
    let cursor = 0;
    for (const thread of ranges) {
        const start = Math.max(cursor, thread.anchor!.start!);
        const end = Math.max(
            start,
            Math.min(caption.length, thread.anchor!.end!),
        );
        if (start > cursor) {
            segments.push({ text: caption.slice(cursor, start), thread: null });
        }
        if (end > start) {
            segments.push({ text: caption.slice(start, end), thread });
            cursor = end;
        }
    }
    if (cursor < caption.length) {
        segments.push({ text: caption.slice(cursor), thread: null });
    }
    if (segments.length === 0) {
        segments.push({ text: caption, thread: null });
    }

    const handleMouseUp = () => {
        if (!canComment || !ref.current) {
            return;
        }
        const sel = window.getSelection();
        if (!sel || sel.isCollapsed || sel.rangeCount === 0) {
            setSelection(null);
            return;
        }
        const range = sel.getRangeAt(0);
        if (!ref.current.contains(range.commonAncestorContainer)) {
            return;
        }
        const a = offsetWithin(
            ref.current,
            range.startContainer,
            range.startOffset,
        );
        const b = offsetWithin(
            ref.current,
            range.endContainer,
            range.endOffset,
        );
        const start = Math.min(a, b);
        const end = Math.max(a, b);
        if (end > start) {
            setSelection({ start, end, quote: caption.slice(start, end) });
        }
    };

    return (
        <div className="grid gap-2">
            <p
                ref={ref}
                onMouseUp={handleMouseUp}
                className="bg-muted/40 text-foreground rounded-xl border p-3 text-sm leading-relaxed whitespace-pre-wrap"
            >
                {segments.map((segment, i) =>
                    segment.thread ? (
                        <mark
                            key={i}
                            onClick={() => onSelectThread(segment.thread!.id)}
                            className={cn(
                                'cursor-pointer rounded px-0.5',
                                segment.thread.resolved
                                    ? 'bg-success/20 text-foreground'
                                    : selectedId === segment.thread.id
                                      ? 'bg-ink text-ink-foreground'
                                      : 'bg-info/25 text-foreground',
                            )}
                        >
                            {segment.text}
                        </mark>
                    ) : (
                        <span key={i}>{segment.text}</span>
                    ),
                )}
            </p>
            {selection && canComment && (
                <div className="flex flex-wrap items-center gap-2">
                    <span className="text-muted-foreground text-xs">
                        “
                        {selection.quote.length > 48
                            ? selection.quote.slice(0, 48) + '…'
                            : selection.quote}
                        ”
                    </span>
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        onClick={() => {
                            onSelectRange(
                                selection.start,
                                selection.end,
                                selection.quote,
                            );
                            setSelection(null);
                            window.getSelection()?.removeAllRanges();
                        }}
                    >
                        Comment on selection
                    </Button>
                </div>
            )}
        </div>
    );
}
