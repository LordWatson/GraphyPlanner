import type { ReactNode } from 'react';
import { useState } from 'react';
import { cn } from '@/lib/utils';

export type KanbanColumn<T> = {
    key: string;
    label: string;
    /** The status a card takes when dropped here; null = not a drop target. */
    dropStatus: string | null;
    items: T[];
    tone?: string;
};

/**
 * Campaigns enhancement C-4.3 — a generic status board, built to be shared with the content hub
 * (I-4.1). Columns scroll horizontally; each card says which columns it may be dropped on
 * (`dropTargets`), and only those light up while it's dragged — an illegal move is never offered.
 * Keyboard and touch users get the same moves through `renderCard`'s own "Move to" menu.
 */
export function KanbanBoard<T extends { id: number }>({
    columns,
    dropTargets,
    renderCard,
    onMove,
    readOnly = false,
    emptyHint = 'Nothing here.',
}: {
    columns: KanbanColumn<T>[];
    dropTargets: (item: T) => string[];
    renderCard: (item: T) => ReactNode;
    onMove: (item: T, status: string) => void;
    readOnly?: boolean;
    emptyHint?: string;
}) {
    const [dragging, setDragging] = useState<T | null>(null);
    const [over, setOver] = useState<string | null>(null);
    const allowed = dragging ? dropTargets(dragging) : [];

    return (
        <div className="-mx-4 overflow-x-auto px-4 pb-2 md:-mx-10 md:px-10">
            <div className="flex min-w-max gap-3">
                {columns.map((column) => {
                    const legal =
                        column.dropStatus !== null &&
                        allowed.includes(column.dropStatus);

                    return (
                        <section
                            key={column.key}
                            aria-label={column.label}
                            onDragOver={(event) => {
                                if (legal) {
                                    event.preventDefault();
                                    setOver(column.key);
                                }
                            }}
                            onDragLeave={() =>
                                setOver((current) =>
                                    current === column.key ? null : current,
                                )
                            }
                            onDrop={(event) => {
                                event.preventDefault();

                                if (dragging && legal && column.dropStatus) {
                                    onMove(dragging, column.dropStatus);
                                }

                                setDragging(null);
                                setOver(null);
                            }}
                            className={cn(
                                'bg-muted/50 flex w-72 shrink-0 flex-col gap-2 rounded-2xl border border-transparent p-2 transition-colors',
                                dragging &&
                                    legal &&
                                    'border-primary/40 bg-accent/40 border-dashed',
                                dragging && !legal && 'opacity-55',
                                over === column.key &&
                                    'border-primary bg-accent/70',
                            )}
                        >
                            <header className="flex items-center justify-between gap-2 px-2 pt-1 pb-1.5">
                                <span className="flex items-center gap-2 text-[13px] font-semibold">
                                    {column.tone && (
                                        <span
                                            aria-hidden
                                            className={cn(
                                                'size-1.5 rounded-full',
                                                column.tone,
                                            )}
                                        />
                                    )}
                                    {column.label}
                                </span>
                                <span className="text-muted-foreground font-mono text-[11px] tabular-nums">
                                    {column.items.length}
                                </span>
                            </header>
                            <ol className="flex min-h-16 flex-col gap-2">
                                {column.items.length === 0 ? (
                                    <li className="text-muted-foreground/80 px-2 py-4 text-center text-xs">
                                        {emptyHint}
                                    </li>
                                ) : (
                                    column.items.map((item) => {
                                        const draggable =
                                            !readOnly &&
                                            dropTargets(item).length > 0;

                                        return (
                                            <li
                                                key={item.id}
                                                draggable={draggable}
                                                onDragStart={(event) => {
                                                    event.dataTransfer.effectAllowed =
                                                        'move';
                                                    setDragging(item);
                                                }}
                                                onDragEnd={() => {
                                                    setDragging(null);
                                                    setOver(null);
                                                }}
                                                className={cn(
                                                    draggable &&
                                                        'cursor-grab active:cursor-grabbing',
                                                    dragging?.id === item.id &&
                                                        'opacity-50',
                                                )}
                                            >
                                                {renderCard(item)}
                                            </li>
                                        );
                                    })
                                )}
                            </ol>
                        </section>
                    );
                })}
            </div>
        </div>
    );
}
