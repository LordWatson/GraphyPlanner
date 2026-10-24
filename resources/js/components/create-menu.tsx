import { ChevronDown, Plus } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useCreateActions } from '@/lib/create-actions';

/**
 * MOVE 4 (ux-overhaul): one Create. A single, always-visible "New" affordance in the workspace
 * header, so creating a post/client/report/campaign no longer means knowing ⌘K or hunting for a
 * per-page button. It reads its actions from `useCreateActions` (the same set the command palette
 * shows), gated by the viewer's workspace + role. Nothing to create → it renders nothing.
 */
export function CreateMenu() {
    const actions = useCreateActions();

    if (actions.length === 0) {
        return null;
    }

    // One obvious action → a plain button; several → a menu.
    if (actions.length === 1) {
        const only = actions[0];

        return (
            <Button
                variant="secondary"
                size="sm"
                className="rounded-full"
                onClick={only.perform}
            >
                <Plus />
                <span className="hidden sm:inline">New</span>
            </Button>
        );
    }

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button variant="secondary" size="sm" className="rounded-full">
                    <Plus />
                    <span className="hidden sm:inline">New</span>
                    <ChevronDown className="opacity-70" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-64">
                {actions.map((action) => (
                    <DropdownMenuItem
                        key={action.id}
                        onSelect={action.perform}
                        className="flex items-center gap-2.5 py-2"
                    >
                        <span className="bg-accent text-accent-foreground flex size-7 shrink-0 items-center justify-center rounded-full">
                            <action.icon className="size-3.5" />
                        </span>
                        <span className="flex min-w-0 flex-col">
                            <span className="text-sm font-medium">
                                {action.label}
                            </span>
                            <span className="text-muted-foreground truncate text-xs">
                                {action.hint}
                            </span>
                        </span>
                    </DropdownMenuItem>
                ))}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
