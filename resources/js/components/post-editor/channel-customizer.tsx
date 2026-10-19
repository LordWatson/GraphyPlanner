import { RotateCcw } from 'lucide-react';
import { HashtagInput } from '@/components/hashtag-input';
import { PlatformIcon } from '@/components/platform-icon';
import type {
    ChannelState,
    TargetAccountData,
} from '@/components/post-editor/types';
import { EMPTY_CHANNEL, isCustomized } from '@/components/post-editor/types';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { publishedCaption } from '@/lib/media-format';
import type { PlatformCapability } from '@/lib/platforms';
import { capabilityOf, resolveFormat, usePlatforms } from '@/lib/platforms';
import { cn } from '@/lib/utils';

const AUTO = '__auto';

const textareaClass =
    'border-input bg-card focus-visible:border-ring focus-visible:ring-ring/40 dark:bg-input/30 placeholder:text-muted-foreground flex min-h-28 w-full resize-y rounded-lg border px-3.5 py-3 text-[15px] leading-relaxed outline-none focus-visible:ring-[3px]';

function Count({
    used,
    limit,
    unit,
    soft = false,
}: {
    used: number;
    limit: number;
    unit: string;
    soft?: boolean;
}) {
    const over = used > limit;

    return (
        <span
            className={cn(
                'font-mono text-[11px] tabular-nums',
                over
                    ? soft
                        ? 'text-warning'
                        : 'text-destructive'
                    : 'text-muted-foreground',
            )}
        >
            {used.toLocaleString()}/{limit.toLocaleString()} {unit}
        </span>
    );
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

/**
 * Steps M-2.2 / M-2.3 — "Customize for {platform}": one tab per selected channel to change its
 * format, caption, hashtags, first comment and link, each falling back to the main post when left
 * alone. Counts are against the caption *as it publishes there* (hashtags and link included), from
 * the capability registry.
 */
export function ChannelCustomizer({
    accounts,
    channels,
    onChange,
    master,
    mediaKinds,
    hashtagSuggestions,
}: {
    accounts: TargetAccountData[];
    channels: Record<number, ChannelState>;
    onChange: (accountId: number, next: ChannelState) => void;
    master: {
        caption: string;
        hashtags: string[];
        firstComment: string;
        link: string;
    };
    mediaKinds: string[];
    hashtagSuggestions: string[];
}) {
    const registry = usePlatforms();

    if (accounts.length === 0) {
        return (
            <p className="text-muted-foreground border-border rounded-xl border border-dashed px-4 py-6 text-center text-sm">
                Tick a channel above to tailor the post for it.
            </p>
        );
    }

    return (
        <Tabs defaultValue={String(accounts[0].id)} className="grid gap-4">
            <TabsList className="h-auto max-w-full flex-wrap justify-start gap-1 overflow-x-auto">
                {accounts.map((account) => (
                    <TabsTrigger
                        key={account.id}
                        value={String(account.id)}
                        className="gap-1.5"
                    >
                        <PlatformIcon
                            platform={account.platform}
                            className="size-5 rounded-md border-0 bg-transparent"
                        />
                        <span className="max-w-28 truncate">
                            {account.handle}
                        </span>
                        {isCustomized(channels[account.id]) && (
                            <span
                                className="bg-primary size-1.5 rounded-full"
                                aria-label="Customized"
                            />
                        )}
                    </TabsTrigger>
                ))}
            </TabsList>

            {accounts.map((account) => {
                const capability = capabilityOf(registry, account.platform);
                const channel = channels[account.id] ?? EMPTY_CHANNEL;
                const set = (patch: Partial<ChannelState>) =>
                    onChange(account.id, { ...channel, ...patch });
                const caption = channel.caption ?? master.caption;
                const tags = channel.hashtags ?? master.hashtags;
                const link = channel.link ?? master.link;
                const published = publishedCaption(
                    caption,
                    tags,
                    capability,
                    link,
                );
                const resolved = resolveFormat(
                    capability,
                    channel.format,
                    mediaKinds,
                );
                const autoLabel = resolveFormat(capability, null, mediaKinds);
                const id = `channel-${account.id}`;

                return (
                    <TabsContent
                        key={account.id}
                        value={String(account.id)}
                        className="grid gap-5"
                    >
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <p className="text-muted-foreground text-sm">
                                {isCustomized(channel)
                                    ? `Customized for ${capability.label}.`
                                    : `Uses the main post. Change anything here and only ${capability.label} gets it.`}
                            </p>
                            {isCustomized(channel) && (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    onClick={() =>
                                        onChange(account.id, {
                                            ...EMPTY_CHANNEL,
                                            format: channel.format,
                                        })
                                    }
                                >
                                    <RotateCcw />
                                    Reset to main post
                                </Button>
                            )}
                        </div>

                        {capability.formats.length > 1 && (
                            <div className="grid gap-2 sm:max-w-xs">
                                <Label htmlFor={`${id}-format`}>Format</Label>
                                <Select
                                    value={channel.format ?? AUTO}
                                    onValueChange={(value) =>
                                        set({
                                            format:
                                                value === AUTO ? null : value,
                                        })
                                    }
                                >
                                    <SelectTrigger id={`${id}-format`}>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={AUTO}>
                                            Auto —{' '}
                                            {autoLabel?.label ??
                                                'from the media'}
                                        </SelectItem>
                                        {capability.formats.map((format) => (
                                            <SelectItem
                                                key={format.key}
                                                value={format.key}
                                            >
                                                {format.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {resolved && (
                                    <p className="text-muted-foreground text-xs">
                                        {resolved.min_items ===
                                        resolved.max_items
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
                            <div className="flex items-center justify-between gap-2">
                                <label className="flex cursor-pointer items-center gap-2 text-sm font-medium">
                                    <Checkbox
                                        checked={channel.caption !== null}
                                        onCheckedChange={(checked) =>
                                            set({
                                                caption: checked
                                                    ? master.caption
                                                    : null,
                                            })
                                        }
                                    />
                                    Write a different caption for{' '}
                                    {capability.label}
                                </label>
                                <Count
                                    used={published.length}
                                    limit={capability.caption_limit}
                                    unit="chars"
                                />
                            </div>
                            {channel.caption !== null && (
                                <textarea
                                    aria-label={`Caption for ${account.handle}`}
                                    dir="auto"
                                    rows={5}
                                    value={channel.caption}
                                    onChange={(e) =>
                                        set({ caption: e.target.value })
                                    }
                                    className={textareaClass}
                                />
                            )}
                        </div>

                        {capability.hashtags ? (
                            <div className="grid gap-2">
                                <div className="flex items-center justify-between gap-2">
                                    <label className="flex cursor-pointer items-center gap-2 text-sm font-medium">
                                        <Checkbox
                                            checked={channel.hashtags !== null}
                                            onCheckedChange={(checked) =>
                                                set({
                                                    hashtags: checked
                                                        ? [...master.hashtags]
                                                        : null,
                                                })
                                            }
                                        />
                                        Use different hashtags
                                    </label>
                                    <Count
                                        used={tags.length}
                                        limit={capability.max_hashtags}
                                        unit="tags"
                                        soft
                                    />
                                </div>
                                {channel.hashtags !== null && (
                                    <HashtagInput
                                        id={`${id}-hashtags`}
                                        value={channel.hashtags}
                                        onChange={(next) =>
                                            set({ hashtags: next })
                                        }
                                        suggestions={hashtagSuggestions}
                                        placeholder="Start typing a hashtag…"
                                    />
                                )}
                            </div>
                        ) : (
                            master.hashtags.length > 0 && (
                                <p className="text-muted-foreground text-xs">
                                    {capability.label} doesn’t use hashtags, so
                                    they’re left off here.
                                </p>
                            )
                        )}

                        <div className="grid gap-5 sm:grid-cols-2">
                            {capability.first_comment && (
                                <div className="grid content-start gap-2">
                                    <Label htmlFor={`${id}-comment`}>
                                        First comment
                                    </Label>
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
                                            'Same as the main post'
                                        }
                                    />
                                </div>
                            )}
                            <div className="grid content-start gap-2">
                                <Label htmlFor={`${id}-link`}>Link</Label>
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
                                    placeholder={
                                        master.link || 'Same as the main post'
                                    }
                                />
                                <p className="text-muted-foreground text-xs">
                                    {linkHint(capability)}
                                </p>
                            </div>
                        </div>
                    </TabsContent>
                );
            })}
        </Tabs>
    );
}

/**
 * The hidden form fields for every selected channel, in one place so each target's schedule and
 * overrides share an index. An override left alone is sent empty (null — use the master); a
 * caption deliberately emptied is sent as the "none" marker.
 */
export function TargetFields({
    accountIds,
    schedule,
    channels,
}: {
    accountIds: number[];
    schedule: Record<number, { date: string; time: string }>;
    channels: Record<number, ChannelState>;
}) {
    if (accountIds.length === 0) {
        // Still send the field so saving clears the last channel.
        return <input type="hidden" name="targets" value="" />;
    }

    return (
        <>
            {accountIds.map((accountId, i) => {
                const channel = channels[accountId] ?? EMPTY_CHANNEL;
                const field = (name: string) => `targets[${i}][${name}]`;

                return (
                    <span key={accountId} hidden>
                        <input
                            type="hidden"
                            name={field('social_account_id')}
                            value={accountId}
                        />
                        <input
                            type="hidden"
                            name={field('scheduled_local_date')}
                            value={schedule[accountId]?.date ?? ''}
                        />
                        <input
                            type="hidden"
                            name={field('scheduled_local_time')}
                            value={schedule[accountId]?.time ?? ''}
                        />
                        <input
                            type="hidden"
                            name={field('caption_override')}
                            value={
                                channel.caption === null
                                    ? ''
                                    : channel.caption === ''
                                      ? '__none__'
                                      : channel.caption
                            }
                        />
                        <input
                            type="hidden"
                            name={field('hashtags_override')}
                            value={
                                channel.hashtags === null
                                    ? ''
                                    : JSON.stringify(channel.hashtags)
                            }
                        />
                        <input
                            type="hidden"
                            name={field('first_comment')}
                            value={channel.firstComment ?? ''}
                        />
                        <input
                            type="hidden"
                            name={field('link_url')}
                            value={channel.link ?? ''}
                        />
                        <input
                            type="hidden"
                            name={field('format')}
                            value={channel.format ?? ''}
                        />
                    </span>
                );
            })}
        </>
    );
}
