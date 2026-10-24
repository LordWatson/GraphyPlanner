import { ArrowLeft, ArrowRight, Check, Keyboard } from 'lucide-react';
import { usePostEditorContext } from '@/components/post-editor/editor/editor-context';
import { editorSteps } from '@/components/post-editor/editor/editor-steps';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

/**
 * The single action bar under the workspace: the save state, Back / Next between steps, and
 * Save. It is the one forward path — Next advances (Settings → Review & send); the Review step
 * has no Next, because its body carries the recommended move. An explicit Save shows only for posts
 * that do not autosave; autosaving drafts save themselves (the status shows "Autosaving…", and
 * ⌘/Ctrl+S still forces a save), so no Save button is drawn for them.
 *
 * On phones it is a slim, single-row strip docked directly above the global bottom tab bar:
 * an icon-only Back, a minimal save state (just a tick when all saved), then Save and Next.
 * On `md+` it becomes the floating rounded pill above the workspace, where there is no tab bar.
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
                // Phones: a slim, full-width strip docked directly ABOVE the global bottom tab bar
                // (56px tall + the safe-area inset), so the two never overlap. One row, never
                // wrapping. `fixed` is safe here — the editor has no transformed ancestor to trap it.
                'bg-card/95 fixed inset-x-0 bottom-[calc(3.5rem+env(safe-area-inset-bottom))] z-30 flex items-center gap-1.5 border-t px-2.5 py-1.5 backdrop-blur transition-colors',
                // Desktop: the floating rounded pill above the workspace (no tab bar at this size).
                'md:shadow-paper md:sticky md:inset-x-auto md:bottom-4 md:z-10 md:flex-wrap md:gap-x-3 md:rounded-full md:border md:py-2 md:pr-2 md:pl-5',
                isDirty ? 'border-foreground/20' : 'border-border',
            )}
        >
            {prev ? (
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    className="shrink-0"
                    aria-label={`Back to ${prev.label}`}
                    onClick={() => nav.goToStep(prev.id)}
                >
                    <ArrowLeft />
                    <span className="hidden sm:inline">{prev.label}</span>
                </Button>
            ) : (
                <span className="w-1" />
            )}

            <span
                className="text-muted-foreground mx-auto flex min-w-0 items-center gap-1.5 text-sm"
                aria-live="polite"
            >
                {isDirty ? (
                    <>
                        <span className="bg-warning size-2 shrink-0 rounded-full" />
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
                        <Check className="text-success size-4 shrink-0" />
                        {/* Phones show just the tick to stay slim; desktop spells it out. */}
                        <span className="hidden sm:inline">
                            {savedTime ? `Saved · ${savedTime}` : 'All saved'}
                        </span>
                    </span>
                )}
            </span>

            <span className="flex shrink-0 items-center gap-1">
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    className="hidden size-8 sm:inline-flex"
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
                        size="sm"
                        onClick={onDiscard}
                        disabled={processing}
                        className="hidden sm:inline-flex"
                    >
                        Discard
                    </Button>
                )}
                {/* Autosaving drafts save themselves (and still respond to ⌘/Ctrl+S), so the
                    explicit Save would only be noise — drop it and let the status carry it. */}
                {!autosaves && (
                    <Button
                        type="submit"
                        variant="secondary"
                        size="sm"
                        disabled={processing || !isDirty}
                    >
                        {processing ? 'Saving…' : 'Save'}
                    </Button>
                )}
                {next && (
                    <Button
                        type="button"
                        size="sm"
                        onClick={() => nav.goToStep(next.id)}
                    >
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
