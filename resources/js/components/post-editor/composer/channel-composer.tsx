import { Link2, MessageCircle, RotateCcw, Split } from 'lucide-react';
import type { Ref } from 'react';
import { useState } from 'react';
import { HashtagInput } from '@/components/hashtag-input';
import { ChannelFormatField } from '@/components/post-editor/composer/channel-format-field';
import type { MasterContent } from '@/components/post-editor/composer/composer-parts';
import {
    AddChip,
    CaptionMeter,
    captionClass,
    InheritTag,
    linkHint,
    sameTags,
    usageFor,
} from '@/components/post-editor/composer/composer-parts';
import type {
    ChannelState,
    TargetAccountData,
} from '@/components/post-editor/types';
import { EMPTY_CHANNEL, isCustomized } from '@/components/post-editor/types';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { capabilityOf, usePlatforms } from '@/lib/platforms';
import { cn } from '@/lib/utils';

/**
 * One channel's view of the post: the same fields show what that channel will publish; typing
 * gives the channel its own version (Figma-style overrides) and "Reset" hands it back.
 */
export function ChannelComposer({
    account,
    master,
    channels,
    onChannelChange,
    mediaKinds,
    hashtagSuggestions,
    captionRef,
}: {
    account: TargetAccountData;
    master: MasterContent;
    channels: Record<number, ChannelState>;
    onChannelChange: (accountId: number, next: ChannelState) => void;
    mediaKinds: string[];
    hashtagSuggestions: string[];
    captionRef?: Ref<HTMLTextAreaElement>;
}) {
    const registry = usePlatforms();
    const [showLink, setShowLink] = useState(false);
    const [showComment, setShowComment] = useState(false);
    const capability = capabilityOf(registry, account.platform);
    const label = capability.label || account.platform_label;
    const channel = channels[account.id] ?? EMPTY_CHANNEL;
    const set = (patch: Partial<ChannelState>) =>
        onChannelChange(account.id, { ...channel, ...patch });
    const { used, limit } = usageFor(account, channel, master, registry);
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
                <ChannelFormatField
                    id={`${id}-format`}
                    capability={capability}
                    format={channel.format}
                    mediaKinds={mediaKinds}
                    onChange={(format) => set({ format })}
                />
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
