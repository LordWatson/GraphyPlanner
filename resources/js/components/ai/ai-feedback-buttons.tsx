import { router } from '@inertiajs/react';
import { ThumbsDown, ThumbsUp } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';

type Rating = 'up' | 'down';

/**
 * Step M-7.1 (dev plan Step 2.9) — thumbs up/down on an AI suggestion (a trend, an idea, a post's
 * AI captions). Posts to the given endpoint; a thumbs-down reveals an optional one-line reason the
 * team can learn from. Idempotent server-side, so re-rating simply updates the stored row.
 */
export function AiFeedbackButtons({
    url,
    initialRating = null,
    label = 'Was this helpful?',
    className,
}: {
    url: string;
    initialRating?: Rating | null;
    label?: string;
    className?: string;
}) {
    const [rating, setRating] = useState<Rating | null>(initialRating);
    const [showReason, setShowReason] = useState(false);
    const [reason, setReason] = useState('');
    const [saving, setSaving] = useState(false);

    const submit = (next: Rating, withReason?: string) => {
        setSaving(true);
        router.post(
            url,
            { rating: next, reason: withReason || undefined },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => setRating(next),
                onFinish: () => setSaving(false),
            },
        );
    };

    return (
        <div className={cn('flex flex-wrap items-center gap-2', className)}>
            <span className="text-muted-foreground text-xs">{label}</span>
            <Button
                type="button"
                variant={rating === 'up' ? 'secondary' : 'ghost'}
                size="icon"
                className="size-8"
                aria-pressed={rating === 'up'}
                aria-label="Helpful"
                disabled={saving}
                onClick={() => {
                    setShowReason(false);
                    submit('up');
                }}
            >
                <ThumbsUp className="size-4" />
            </Button>
            <Button
                type="button"
                variant={rating === 'down' ? 'secondary' : 'ghost'}
                size="icon"
                className="size-8"
                aria-pressed={rating === 'down'}
                aria-label="Not helpful"
                disabled={saving}
                onClick={() => {
                    setShowReason(true);
                    submit('down');
                }}
            >
                <ThumbsDown className="size-4" />
            </Button>

            {showReason && rating === 'down' && (
                <form
                    className="flex items-center gap-2"
                    onSubmit={(e) => {
                        e.preventDefault();
                        submit('down', reason);
                        setShowReason(false);
                    }}
                >
                    <Input
                        value={reason}
                        onChange={(e) => setReason(e.target.value)}
                        placeholder="What was off? (optional)"
                        className="h-8 w-52"
                        maxLength={500}
                    />
                    <Button
                        type="submit"
                        size="sm"
                        variant="outline"
                        disabled={saving}
                    >
                        Send
                    </Button>
                </form>
            )}
        </div>
    );
}
