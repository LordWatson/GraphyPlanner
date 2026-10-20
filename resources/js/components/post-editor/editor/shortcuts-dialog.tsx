import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

const shortcuts: { keys: string[]; label: string }[] = [
    { keys: ['⌘/Ctrl', 'S'], label: 'Save changes' },
    { keys: ['⌘/Ctrl', 'Enter'], label: 'Send for review (you confirm first)' },
    { keys: ['⌘/Ctrl', 'K'], label: 'Open the command palette' },
    { keys: ['?'], label: 'Show these shortcuts' },
    { keys: ['Esc'], label: 'Close a dialog or menu' },
];

/**
 * Step I-4.6 / M-1.6 — the editor's keyboard shortcuts, opened with `?` (outside a text field) or
 * the keyboard button on the save bar.
 */
export function ShortcutsDialog({
    open,
    onOpenChange,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Keyboard shortcuts</DialogTitle>
                    <DialogDescription>
                        Work through a post without reaching for the mouse.
                    </DialogDescription>
                </DialogHeader>
                <dl className="divide-border/70 border-border divide-y rounded-xl border">
                    {shortcuts.map((shortcut) => (
                        <div
                            key={shortcut.label}
                            className="flex items-center justify-between gap-4 px-4 py-2.5 text-sm"
                        >
                            <dt>{shortcut.label}</dt>
                            <dd className="flex shrink-0 items-center gap-1">
                                {shortcut.keys.map((key) => (
                                    <kbd
                                        key={key}
                                        className="border-border bg-muted rounded-md border px-1.5 py-0.5 font-mono text-[11px]"
                                    >
                                        {key}
                                    </kbd>
                                ))}
                            </dd>
                        </div>
                    ))}
                </dl>
            </DialogContent>
        </Dialog>
    );
}
