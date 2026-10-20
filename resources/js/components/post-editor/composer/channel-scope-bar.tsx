import { Plus } from 'lucide-react';
import { platformIcon } from '@/components/platform-icon';
import type {
    ComposerScope,
    MasterContent,
} from '@/components/post-editor/composer/composer-parts';
import { usageFor } from '@/components/post-editor/composer/composer-parts';
import type {
    ChannelState,
    TargetAccountData,
} from '@/components/post-editor/types';
import { EMPTY_CHANNEL, isCustomized } from '@/components/post-editor/types';
import { Button } from '@/components/ui/button';
import { usePlatforms } from '@/lib/platforms';
import { cn } from '@/lib/utils';

/**
 * The one channel switcher for the whole editor: "All channels" edits the main post; a channel
 * edits only what that channel overrides, and the preview follows. Dots mark channels with their
 * own content; a channel whose caption won't fit says so.
 */
export function ChannelScopeBar({
    scope,
    onScopeChange,
    accounts,
    channels,
    master,
    onAddChannels,
}: {
    scope: ComposerScope;
    onScopeChange: (scope: ComposerScope) => void;
    accounts: TargetAccountData[];
    channels: Record<number, ChannelState>;
    master: MasterContent;
    onAddChannels: () => void;
}) {
    const registry = usePlatforms();
    const pill =
        'inline-flex h-8 shrink-0 items-center gap-1.5 rounded-full px-3 text-xs font-medium whitespace-nowrap transition-colors focus-visible:ring-ring/50 outline-none focus-visible:ring-[3px]';

    return (
        <div className="flex min-w-0 items-center gap-2">
            <div
                role="tablist"
                aria-label="Edit content for"
                className="bg-muted flex min-w-0 gap-1 overflow-x-auto rounded-full p-1"
            >
                <button
                    type="button"
                    role="tab"
                    aria-selected={scope === 'all'}
                    onClick={() => onScopeChange('all')}
                    className={cn(
                        pill,
                        scope === 'all'
                            ? 'bg-primary text-primary-foreground'
                            : 'text-muted-foreground hover:text-foreground',
                    )}
                >
                    All channels
                </button>
                {accounts.map((account) => {
                    const Icon = platformIcon(account.platform);
                    const channel = channels[account.id] ?? EMPTY_CHANNEL;
                    const active = scope === account.id;
                    const { used, limit, capability } = usageFor(
                        account,
                        channel,
                        master,
                        registry,
                    );
                    const over = used > limit;
                    const customized = isCustomized(channel);

                    return (
                        <button
                            key={account.id}
                            type="button"
                            role="tab"
                            aria-selected={active}
                            onClick={() => onScopeChange(account.id)}
                            title={`${capability.label || account.platform_label} · ${account.handle}`}
                            className={cn(
                                pill,
                                active
                                    ? 'bg-primary text-primary-foreground'
                                    : 'text-muted-foreground hover:text-foreground',
                            )}
                        >
                            <Icon className="size-3.5" />
                            <span className="max-w-28 truncate">
                                {account.handle}
                            </span>
                            {over ? (
                                <span
                                    className={cn(
                                        'rounded-full px-1.5 font-mono text-[10px] leading-4',
                                        active
                                            ? 'bg-primary-foreground text-primary'
                                            : 'bg-destructive/10 text-destructive',
                                    )}
                                >
                                    Too long
                                </span>
                            ) : (
                                customized && (
                                    <span
                                        className={cn(
                                            'size-1.5 rounded-full',
                                            active
                                                ? 'bg-primary-foreground'
                                                : 'bg-primary',
                                        )}
                                    >
                                        <span className="sr-only">
                                            (customized)
                                        </span>
                                    </span>
                                )
                            )}
                        </button>
                    );
                })}
            </div>
            <Button
                type="button"
                variant="ghost"
                size="sm"
                onClick={onAddChannels}
                className="shrink-0"
            >
                <Plus />
                {accounts.length === 0 ? 'Pick channels' : 'Channels'}
            </Button>
        </div>
    );
}
