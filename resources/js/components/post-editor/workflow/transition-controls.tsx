import { Ellipsis, Trash2 } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type { TransitionAction } from '@/lib/post-workflow';

/**
 * The rarer moves — manual system overrides and archiving — plus delete, tucked behind one "more"
 * button so the common forward/back steps stay front and centre. Forward and back moves are shown
 * as their own buttons by `PostWorkflow`, never hidden here.
 */
export function MoveMenu({
    overflow,
    canDelete,
    onSelect,
    onDelete,
}: {
    overflow: TransitionAction[];
    canDelete: boolean;
    onSelect: (action: TransitionAction) => void;
    onDelete: () => void;
}) {
    if (overflow.length === 0 && !canDelete) {
        return null;
    }

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    aria-label="More status moves"
                >
                    <Ellipsis />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-56">
                <DropdownMenuLabel className="eyebrow text-muted-foreground">
                    More moves
                </DropdownMenuLabel>
                {overflow.map((action, index) => {
                    const Icon = action.icon;
                    const startsArchive =
                        action.intent === 'archive' && index > 0;

                    return (
                        <div key={action.to}>
                            {startsArchive && <DropdownMenuSeparator />}
                            <DropdownMenuItem
                                variant={
                                    action.intent === 'archive'
                                        ? 'destructive'
                                        : 'default'
                                }
                                onSelect={() => onSelect(action)}
                            >
                                <Icon />
                                {action.label}
                            </DropdownMenuItem>
                        </div>
                    );
                })}
                {canDelete && (
                    <>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                            variant="destructive"
                            onSelect={onDelete}
                        >
                            <Trash2 />
                            Delete post
                        </DropdownMenuItem>
                    </>
                )}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
