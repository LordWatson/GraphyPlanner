import { Check, Keyboard } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

/** The sticky save bar under the form: saved / unsaved state, Discard (undoable) and Save. */
export function SaveBar({
    isDirty,
    processing,
    onDiscard,
    onShowShortcuts,
}: {
    isDirty: boolean;
    processing: boolean;
    onDiscard: () => void;
    onShowShortcuts: () => void;
}) {
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
                        <kbd className="text-muted-foreground hidden font-mono text-[11px] sm:inline">
                            Ctrl/⌘ S
                        </kbd>
                    </>
                ) : (
                    <span className="text-muted-foreground flex items-center gap-1.5">
                        <Check className="text-success size-4" />
                        All changes saved
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
