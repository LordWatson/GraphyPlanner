import { Link2, MessageCircle, Plus, RotateCcw, Split } from 'lucide-react';
import type { ReactNode, Ref } from 'react';
import { useState } from 'react';
import { HashtagInput } from '@/components/hashtag-input';
import InputError from '@/components/input-error';
import { platformIcon } from '@/components/platform-icon';
import type {
    ChannelState,
    TargetAccountData,
} from '@/components/post-editor/types';
import { EMPTY_CHANNEL, isCustomized } from '@/components/post-editor/types';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { publishedCaption } from '@/lib/media-format';
import type { PlatformCapability, PlatformRegistry } from '@/lib/platforms';
import { capabilityOf, resolveFormat, usePlatforms } from '@/lib/platforms';
import { cn } from '@/lib/utils';

/** What the composer is editing: the main post every channel inherits, or one channel's own take. */
export type ComposerScope = 'all' | number;

export type MasterContent = {
    caption: string;
    hashtags: string[];
    link: string;
    firstComment: string;
};

const AUTO = '__auto';

const captionClass =
    'border-input bg-card focus-visible:border-ring focus-visible:ring-ring/40 dark:bg-input/30 placeholder:text-muted-foreground flex min-h-44 w-full resize-y rounded-lg border px-4 py-3.5 text-[15px] leading-relaxed outline-none focus-visible:ring-[3px]';

/** The caption as it publishes on a channel, against that channel's limit. */
function usageFor(
    account: TargetAccountData,
    channel: ChannelState,
    master: MasterContent,
    registry: PlatformRegistry,
): { used: number; limit: number; capability: PlatformCapability } {
    const capability = capabilityOf(registry, account.platform);

    return {
        used: publishedCaption(
            channel.caption ?? master.caption,
            channel.hashtags ?? master.hashtags,
            capability,
            channel.link ?? master.link,
        ).length,
        limit: capability.caption_limit,
        capability,
    };
}

function sameTags(a: string[], b: string[]): boolean {
    return a.length === b.length && a.every((tag, i) => tag === b[i]);
}

/** Where this channel puts the post's link, in plain words. */
function linkHint(capability: PlatformCapability): string {
    if (capability.link_field) {
        return `Sent as the post’s own link on ${capability.label}, not in the caption.`;
    }

    return capability.links_in_caption
        ? 'Added to the end of the caption.'
        : `${capability.label} doesn’t show links on posts — use the link in bio.`;
}

/** A thin bar + mono count: quiet while there's room, warning near the limit, red over it. */
function CaptionMeter({
    used,
    limit,
    label,
}: {
    used: number;
    limit: number | null;
    label?: string;
}) {
    if (limit === null) {
        return (
            <span className="text-muted-foreground font-mono text-[11px] tabular-nums">
                {used.toLocaleString()} chars
            </span>
        );
    }

    const ratio = limit > 0 ? used / limit : 0;
    const tone =
        ratio > 1 ? 'destructive' : ratio >= 0.9 ? 'warning' : 'neutral';

    return (
        <span className="flex items-center gap-2.5">
            <span
                className="bg-muted relative h-1 w-20 overflow-hidden rounded-full"
                aria-hidden
            >
                <span
                    className={cn(
                        'absolute inset-y-0 left-0 rounded-full transition-[width,background-color]',
                        tone === 'destructive' && 'bg-destructive',
                        tone === 'warning' && 'bg-warning',
                        tone === 'neutral' && 'bg-primary',
                    )}
                    style={{ width: `${Math.min(ratio, 1) * 100}%` }}
                />
            </span>
            <span
                className={cn(
                    'font-mono text-[11px] tabular-nums',
                    tone === 'destructive'
                        ? 'text-destructive'
                        : tone === 'warning'
                          ? 'text-warning'
                          : 'text-muted-foreground',
                )}
            >
                {used.toLocaleString()}/{limit.toLocaleString()}
                {label && <span className="ms-1">{label}</span>}
                {tone === 'destructive' && ' — too long'}
            </span>
        </span>
    );
}

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

/** A "+ Link"-style chip that reveals an optional field. */
function AddChip({
    icon: Icon,
    children,
    onClick,
}: {
    icon: typeof Link2;
    children: ReactNode;
    onClick: () => void;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            className="border-border text-muted-foreground hover:text-foreground hover:border-foreground/30 focus-visible:ring-ring/50 inline-flex h-8 items-center gap-1.5 rounded-full border border-dashed px-3 text-xs font-medium transition-colors outline-none focus-visible:ring-[3px]"
        >
            <Plus className="size-3" />
            <Icon className="size-3.5" />
            {children}
        </button>
    );
}

/** Small mono tag saying whether a field follows the main post or is this channel's own. */
function InheritTag({
    own,
    channelLabel,
    onReset,
}: {
    own: boolean;
    channelLabel: string;
    onReset: () => void;
}) {
    return own ? (
        <span className="flex items-center gap-1.5">
            <span className="text-primary font-mono text-[10px] tracking-[0.08em] uppercase">
                Only on {channelLabel}
            </span>
            <Button
                type="button"
                variant="link"
                className="h-auto p-0 text-[11px]"
                onClick={onReset}
            >
                Reset
            </Button>
        </span>
    ) : (
        <span className="text-muted-foreground font-mono text-[10px] tracking-[0.08em] uppercase">
            Same as all channels
        </span>
    );
}

/**
 * The post's writing surface. In "All channels" it edits the main caption, hashtags, link and
 * first comment. With a channel picked, the same fields show what that channel will publish —
 * typing gives the channel its own version (Figma-style overrides), "Reset" hands it back to the
 * main post. Link and first comment stay tucked behind chips until they're wanted.
 */
export function Composer({
    scope,
    accounts,
    master,
    onMasterChange,
    channels,
    onChannelChange,
    mediaKinds,
    hashtagSuggestions,
    errors,
    aiPanel,
    captionRef,
}: {
    scope: ComposerScope;
    accounts: TargetAccountData[];
    master: MasterContent;
    onMasterChange: (patch: Partial<MasterContent>) => void;
    channels: Record<number, ChannelState>;
    onChannelChange: (accountId: number, next: ChannelState) => void;
    mediaKinds: string[];
    hashtagSuggestions: string[];
    errors: Partial<
        Record<'master_caption' | 'link_url' | 'first_comment', string>
    >;
    /** AI suggestions, shown under the main caption only. */
    aiPanel?: ReactNode;
    captionRef?: Ref<HTMLTextAreaElement>;
}) {
    const registry = usePlatforms();
    const [showLink, setShowLink] = useState(false);
    const [showComment, setShowComment] = useState(false);
    const account =
        scope === 'all' ? null : accounts.find((a) => a.id === scope);

    if (!account) {
        // The tightest fit among channels still following the main caption.
        const worst = accounts
            .filter((a) => (channels[a.id] ?? EMPTY_CHANNEL).caption === null)
            .map((a) => ({
                account: a,
                ...usageFor(
                    a,
                    channels[a.id] ?? EMPTY_CHANNEL,
                    master,
                    registry,
                ),
            }))
            .sort((a, b) => b.used / b.limit - a.used / a.limit)[0];
        const linkShown = showLink || master.link !== '';
        const commentShown = showComment || master.firstComment !== '';

        return (
            <div className="grid gap-5">
                <div className="grid gap-2">
                    <div className="flex items-baseline justify-between gap-3">
                        <Label htmlFor="composer_caption">Caption</Label>
                        <CaptionMeter
                            used={worst ? worst.used : master.caption.length}
                            limit={worst ? worst.limit : null}
                            label={worst ? worst.capability.label : undefined}
                        />
                    </div>
                    <textarea
                        ref={captionRef}
                        id="composer_caption"
                        rows={7}
                        dir="auto"
                        value={master.caption}
                        onChange={(e) =>
                            onMasterChange({ caption: e.target.value })
                        }
                        placeholder="What’s the story? Start writing…"
                        className={captionClass}
                    />
                    <InputError message={errors.master_caption} />
                    {aiPanel}
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="composer_hashtags">Hashtags</Label>
                    <HashtagInput
                        id="composer_hashtags"
                        value={master.hashtags}
                        onChange={(hashtags) => onMasterChange({ hashtags })}
                        suggestions={hashtagSuggestions}
                    />
                </div>

                {(linkShown || commentShown) && (
                    <div className="grid gap-5 sm:grid-cols-2">
                        {linkShown && (
                            <div className="grid content-start gap-2">
                                <Label htmlFor="composer_link">Link</Label>
                                <Input
                                    id="composer_link"
                                    type="url"
                                    autoFocus={showLink && master.link === ''}
                                    value={master.link}
                                    onChange={(e) =>
                                        onMasterChange({ link: e.target.value })
                                    }
                                    placeholder="https://"
                                />
                                <p className="text-muted-foreground text-xs">
                                    Each channel uses it the way it can — pick a
                                    channel above to see how.
                                </p>
                                <InputError message={errors.link_url} />
                            </div>
                        )}
                        {commentShown && (
                            <div className="grid content-start gap-2">
                                <Label htmlFor="composer_comment">
                                    First comment
                                </Label>
                                <Input
                                    id="composer_comment"
                                    dir="auto"
                                    autoFocus={
                                        showComment &&
                                        master.firstComment === ''
                                    }
                                    value={master.firstComment}
                                    onChange={(e) =>
                                        onMasterChange({
                                            firstComment: e.target.value,
                                        })
                                    }
                                    placeholder="Posted as a comment right after"
                                />
                                <InputError message={errors.first_comment} />
                            </div>
                        )}
                    </div>
                )}

                {(!linkShown || !commentShown) && (
                    <div className="flex flex-wrap gap-2">
                        {!linkShown && (
                            <AddChip
                                icon={Link2}
                                onClick={() => setShowLink(true)}
                            >
                                Link
                            </AddChip>
                        )}
                        {!commentShown && (
                            <AddChip
                                icon={MessageCircle}
                                onClick={() => setShowComment(true)}
                            >
                                First comment
                            </AddChip>
                        )}
                    </div>
                )}
            </div>
        );
    }

    const capability = capabilityOf(registry, account.platform);
    const label = capability.label || account.platform_label;
    const channel = channels[account.id] ?? EMPTY_CHANNEL;
    const set = (patch: Partial<ChannelState>) =>
        onChannelChange(account.id, { ...channel, ...patch });
    const { used, limit } = usageFor(account, channel, master, registry);
    const resolved = resolveFormat(capability, channel.format, mediaKinds);
    const autoLabel = resolveFormat(capability, null, mediaKinds);
    const linkShown = showLink || channel.link !== null || master.link !== '';
    const commentShown =
        capability.first_comment &&
        (showComment ||
            channel.firstComment !== null ||
            master.firstComment !== '');
    const id = `composer-${account.id}`;

    return (
        <div className="grid gap-5">
            <div className="bg-accent/60 flex flex-wrap items-center justify-between gap-3 rounded-xl px-4 py-3">
                <p className="flex items-start gap-2.5 text-[13px]">
                    <Split className="text-primary mt-0.5 size-4 shrink-0" />
                    <span>
                        <span className="font-medium">
                            Editing for {label} only.
                        </span>{' '}
                        <span className="text-muted-foreground">
                            Anything you leave alone follows All channels.
                        </span>
                    </span>
                </p>
                {isCustomized(channel) && (
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={() =>
                            onChannelChange(account.id, {
                                ...EMPTY_CHANNEL,
                                format: channel.format,
                            })
                        }
                    >
                        <RotateCcw />
                        Reset {label}
                    </Button>
                )}
            </div>

            {capability.formats.length > 1 && (
                <div className="grid gap-2 sm:max-w-xs">
                    <Label htmlFor={`${id}-format`}>Format</Label>
                    <Select
                        value={channel.format ?? AUTO}
                        onValueChange={(value) =>
                            set({ format: value === AUTO ? null : value })
                        }
                    >
                        <SelectTrigger id={`${id}-format`}>
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={AUTO}>
                                Auto — {autoLabel?.label ?? 'from the media'}
                            </SelectItem>
                            {capability.formats.map((format) => (
                                <SelectItem key={format.key} value={format.key}>
                                    {format.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    {resolved && (
                        <p className="text-muted-foreground text-xs">
                            {resolved.min_items === resolved.max_items
                                ? `Takes ${resolved.max_items} ${resolved.media.join(' or ')}`
                                : `Takes ${resolved.min_items}–${resolved.max_items} items`}
                            {resolved.crop_to
                                ? `, shown ${resolved.crop_to}.`
                                : '.'}
                        </p>
                    )}
                </div>
            )}

            <div className="grid gap-2">
                <div className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                    <span className="flex items-baseline gap-3">
                        <Label htmlFor={`${id}-caption`}>Caption</Label>
                        <InheritTag
                            own={channel.caption !== null}
                            channelLabel={label}
                            onReset={() => set({ caption: null })}
                        />
                    </span>
                    <CaptionMeter used={used} limit={limit} />
                </div>
                <textarea
                    ref={captionRef}
                    id={`${id}-caption`}
                    rows={7}
                    dir="auto"
                    value={channel.caption ?? master.caption}
                    onChange={(e) =>
                        set({
                            caption:
                                e.target.value === master.caption
                                    ? null
                                    : e.target.value,
                        })
                    }
                    placeholder={`Write the ${label} caption…`}
                    className={cn(
                        captionClass,
                        channel.caption === null && 'text-foreground/75',
                    )}
                />
            </div>

            {capability.hashtags ? (
                <div className="grid gap-2">
                    <span className="flex items-baseline gap-3">
                        <Label htmlFor={`${id}-hashtags`}>Hashtags</Label>
                        <InheritTag
                            own={channel.hashtags !== null}
                            channelLabel={label}
                            onReset={() => set({ hashtags: null })}
                        />
                        <span
                            className={cn(
                                'ms-auto font-mono text-[11px] tabular-nums',
                                (channel.hashtags ?? master.hashtags).length >
                                    capability.max_hashtags
                                    ? 'text-warning'
                                    : 'text-muted-foreground',
                            )}
                        >
                            {(channel.hashtags ?? master.hashtags).length}/
                            {capability.max_hashtags} tags
                        </span>
                    </span>
                    <HashtagInput
                        id={`${id}-hashtags`}
                        value={channel.hashtags ?? master.hashtags}
                        onChange={(next) =>
                            set({
                                hashtags: sameTags(next, master.hashtags)
                                    ? null
                                    : next,
                            })
                        }
                        suggestions={hashtagSuggestions}
                    />
                </div>
            ) : (
                master.hashtags.length > 0 && (
                    <p className="text-muted-foreground text-xs">
                        {label} doesn’t use hashtags, so they’re left off here.
                    </p>
                )
            )}

            {(linkShown || commentShown) && (
                <div className="grid gap-5 sm:grid-cols-2">
                    {linkShown && (
                        <div className="grid content-start gap-2">
                            <span className="flex items-baseline gap-3">
                                <Label htmlFor={`${id}-link`}>Link</Label>
                                <InheritTag
                                    own={channel.link !== null}
                                    channelLabel={label}
                                    onReset={() => set({ link: null })}
                                />
                            </span>
                            <Input
                                id={`${id}-link`}
                                type="url"
                                value={channel.link ?? ''}
                                onChange={(e) =>
                                    set({
                                        link:
                                            e.target.value === ''
                                                ? null
                                                : e.target.value,
                                    })
                                }
                                placeholder={master.link || 'https://'}
                            />
                            <p className="text-muted-foreground text-xs">
                                {linkHint(capability)}
                            </p>
                        </div>
                    )}
                    {commentShown && (
                        <div className="grid content-start gap-2">
                            <span className="flex items-baseline gap-3">
                                <Label htmlFor={`${id}-comment`}>
                                    First comment
                                </Label>
                                <InheritTag
                                    own={channel.firstComment !== null}
                                    channelLabel={label}
                                    onReset={() => set({ firstComment: null })}
                                />
                            </span>
                            <Input
                                id={`${id}-comment`}
                                dir="auto"
                                value={channel.firstComment ?? ''}
                                onChange={(e) =>
                                    set({
                                        firstComment:
                                            e.target.value === ''
                                                ? null
                                                : e.target.value,
                                    })
                                }
                                placeholder={
                                    master.firstComment ||
                                    'Posted as a comment right after'
                                }
                            />
                        </div>
                    )}
                </div>
            )}

            {(!linkShown || (capability.first_comment && !commentShown)) && (
                <div className="flex flex-wrap gap-2">
                    {!linkShown && (
                        <AddChip icon={Link2} onClick={() => setShowLink(true)}>
                            Link for {label}
                        </AddChip>
                    )}
                    {capability.first_comment && !commentShown && (
                        <AddChip
                            icon={MessageCircle}
                            onClick={() => setShowComment(true)}
                        >
                            First comment for {label}
                        </AddChip>
                    )}
                </div>
            )}
        </div>
    );
}
