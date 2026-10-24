import { ArrowLeft, ArrowRight, Check, Keyboard } from 'lucide-react';
import { usePostEditorContext } from '@/components/post-editor/editor/editor-context';
import { editorSteps } from '@/components/post-editor/editor/editor-steps';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

/**
 * The single action bar under the workspace: the save state, Back / Next between steps, and
 * Save. It is the one forward path — Next advances (Settings → Review & send); the Review step
 * has no Next, because its body carries the recommended move. Save stays available throughout
 * (and autosaves for draft-like posts).
 */
export function StepActionBar({
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
    const { nav } = usePostEditorContext();
    const index = editorSteps.findIndex((step) => step.id === nav.activeTab);
    const prev = editorSteps[index - 1];
    const next = editorSteps[index + 1];

    const savedTime = savedAt
        ? new Date(savedAt).toLocaleTimeString('en-GB', {
              hour: '2-digit',
              minute: '2-digit',
          })
        : null;

    return (
        <div
            className={cn(
                'bg-card/95 shadow-paper sticky bottom-4 z-10 flex flex-wrap items-center gap-x-3 gap-y-2 rounded-full border py-2 pr-2 pl-3 backdrop-blur transition-colors sm:pl-5',
                isDirty ? 'border-foreground/20' : 'border-border',
            )}
        >
            {prev ? (
                <Button
                    type="button"
                    variant="ghost"
                    onClick={() => nav.setTab(prev.id)}
                >
                    <ArrowLeft />
                    <span className="hidden sm:inline">{prev.label}</span>
                    <span className="sm:hidden">Back</span>
                </Button>
            ) : (
                <span className="w-2" />
            )}

            <span
                className="text-muted-foreground mx-auto flex items-center gap-2 text-sm"
                aria-live="polite"
            >
                {isDirty ? (
                    <>
                        <span className="bg-warning size-2 rounded-full" />
                        <span className="text-foreground">Unsaved</span>
                        {autosaves ? (
                            <span className="hidden text-xs sm:inline">
                                Autosaving…
                            </span>
                        ) : (
                            <kbd className="hidden font-mono text-[11px] sm:inline">
                                Ctrl/⌘ S
                            </kbd>
                        )}
                    </>
                ) : (
                    <span className="flex items-center gap-1.5">
                        <Check className="text-success size-4" />
                        {savedTime ? `Saved · ${savedTime}` : 'All saved'}
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
                    {processing ? 'Saving…' : 'Save'}
                </Button>
                {next && (
                    <Button type="button" onClick={() => nav.setTab(next.id)}>
                        <span className="hidden sm:inline">
                            Next: {next.label}
                        </span>
                        <span className="sm:hidden">Next</span>
                        <ArrowRight />
                    </Button>
                )}
            </span>
        </div>
    );
}
