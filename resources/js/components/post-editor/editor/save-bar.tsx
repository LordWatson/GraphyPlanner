import { Check, Keyboard } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

/** The sticky save bar under the form: saved / unsaved state, Discard (undoable) and Save. */
export function SaveBar({
    isDirty,
    processing,
    autosaves = false,
    savedAt = null,
    onDiscard,
    onShowShortcuts,
}: {
    isDirty: boolean;
    processing: boolean;
    /** Step M-5.3: whether this post autosaves (draft-like + editable). */
    autosaves?: boolean;
    /** Step M-5.3: when the post was last saved, for the "Saved · 12:04" indicator. */
    savedAt?: string | null;
    onDiscard: () => void;
    onShowShortcuts: () => void;
}) {
    const savedTime = savedAt
        ? new Date(savedAt).toLocaleTimeString('en-GB', {
              hour: '2-digit',
              minute: '2-digit',
          })
        : null;

    return (
        <div
            className={cn(
                'bg-card/95 shadow-paper sticky bottom-4 z-10 flex items-center justify-between gap-3 rounded-full border py-2 pr-2 pl-5 backdrop-blur transition-colors',
                isDirty ? 'border-foreground/20' : 'border-border',
            )}
        >
            <span
                className="flex items-center gap-2 text-sm"
                aria-live="polite"
            >
                {isDirty ? (
                    <>
                        <span className="bg-warning size-2 rounded-full" />
                        Unsaved changes
                        {autosaves ? (
                            <span className="text-muted-foreground hidden text-xs sm:inline">
                                Autosaving…
                            </span>
                        ) : (
                            <kbd className="text-muted-foreground hidden font-mono text-[11px] sm:inline">
                                Ctrl/⌘ S
                            </kbd>
                        )}
                    </>
                ) : (
                    <span className="text-muted-foreground flex items-center gap-1.5">
                        <Check className="text-success size-4" />
                        {savedTime
                            ? `Saved · ${savedTime}`
                            : 'All changes saved'}
                    </span>
                )}
            </span>
            <span className="flex items-center gap-1">
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    className="hidden sm:inline-flex"
                    aria-label="Keyboard shortcuts"
                    title="Keyboard shortcuts (?)"
                    onClick={onShowShortcuts}
                >
                    <Keyboard />
                </Button>
                {isDirty && (
                    <Button
                        type="button"
                        variant="ghost"
                        onClick={onDiscard}
                        disabled={processing}
                    >
                        Discard
                    </Button>
                )}
                <Button
                    type="submit"
                    variant="secondary"
                    disabled={processing || !isDirty}
                >
                    {processing ? 'Saving…' : 'Save changes'}
                </Button>
            </span>
        </div>
    );
}
