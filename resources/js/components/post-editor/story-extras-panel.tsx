import { Plus } from 'lucide-react';
import { useEffect, useRef } from 'react';
import { LinkField } from '@/components/post-editor/story-extras/link-field';
import {
    Counter,
    EXTRA_ICONS,
    ExtraCard,
    FieldHint,
} from '@/components/post-editor/story-extras/parts';
import { PollField } from '@/components/post-editor/story-extras/poll-field';
import {
    HashtagField,
    LocationField,
    MentionsField,
} from '@/components/post-editor/story-extras/sticker-fields';
import {
    IfMissedChoice,
    WhoPostsIt,
} from '@/components/post-editor/story-extras/who-posts';
import type { StoryHandoffOptions } from '@/components/post-editor/types';
import { StatusPill } from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import type {
    StoryExtraKey,
    StoryExtras,
    StoryHandoffRecipient,
} from '@/lib/story-extras';
import {
    emptyStoryExtra,
    hasStoryExtras,
    STORY_EXTRA_KEYS,
    STORY_EXTRA_LABELS,
    STORY_LIMITS,
    withoutStoryExtra,
} from '@/lib/story-extras';

export { storyPosterName } from '@/components/post-editor/story-extras/who-posts';

/**
 * Step S-1.4 — the extras on a Story channel: a link sticker, a mention, a hashtag, a location, a
 * poll. Instagram only lets a person add these from its own app, so the moment a Story has one it
 * stops auto-publishing and is posted by hand instead. The panel says so the moment it happens
 * (the pill flips, with one line of why), asks who posts it, and goes back to "Auto-publish" when
 * the last extra is removed.
 *
 * Nothing is shown empty: each extra is added from the "Add" menu. The fields themselves live in
 * `story-extras/`.
 */
export function StoryExtrasPanel({
    id,
    extras,
    recipient,
    onExtrasChange,
    onRecipientChange,
    postLink,
    options,
    assigneeId,
    clientName,
}: {
    id: string;
    extras: StoryExtras | null;
    /** The channel's own choice; null follows the client's default. */
    recipient: StoryHandoffRecipient | null;
    onExtrasChange: (extras: StoryExtras | null) => void;
    onRecipientChange: (recipient: StoryHandoffRecipient | null) => void;
    /** The link this channel would otherwise carry (its own, else the post's). */
    postLink: string;
    options: StoryHandoffOptions;
    assigneeId: string;
    clientName: string;
}) {
    const current = extras ?? {};
    const byHand = hasStoryExtras(extras);
    const available = STORY_EXTRA_KEYS.filter(
        (key) => current[key] === undefined,
    );
    const set = (patch: Partial<StoryExtras>) =>
        onExtrasChange({ ...current, ...patch });
    const sectionRef = useRef<HTMLElement>(null);
    const addRef = useRef<HTMLButtonElement>(null);
    // Where focus goes next: into the extra just added, or back to "Add" after a removal.
    const focusRef = useRef<StoryExtraKey | 'add' | null>(null);
    const remove = (key: StoryExtraKey) => () => {
        focusRef.current = 'add';
        onExtrasChange(withoutStoryExtra(current, key));
    };

    useEffect(() => {
        if (focusRef.current === 'add') {
            focusRef.current = null;
            addRef.current?.focus();
        }
    });

    return (
        <section
            ref={sectionRef}
            aria-labelledby={`${id}-title`}
            className="border-border bg-background/60 grid gap-4 rounded-xl border p-4"
        >
            <div className="flex flex-wrap items-start justify-between gap-x-4 gap-y-2">
                <div className="grid min-w-0 gap-1">
                    <h3 id={`${id}-title`} className="text-sm font-semibold">
                        Story extras
                    </h3>
                    <p
                        className="text-muted-foreground max-w-prose text-[13px] text-pretty"
                        aria-live="polite"
                    >
                        {byHand
                            ? 'Instagram only allows links and stickers from its own app. We’ll get this ready and tell you when it’s time to post.'
                            : 'Add a link, a mention, a poll or another sticker. A story with extras is posted by hand from the Instagram app.'}
                    </p>
                </div>
                <StatusPill
                    kind="storyHandoff"
                    value={byHand ? 'needs_hand_posting' : 'auto'}
                />
            </div>

            {byHand && (
                <ul className="grid gap-3">
                    {current.link !== undefined && (
                        <ExtraCard extra="link" onRemove={remove('link')}>
                            <LinkField
                                id={`${id}-link`}
                                link={current.link}
                                onChange={(link) => set({ link })}
                                postLink={postLink}
                            />
                        </ExtraCard>
                    )}
                    {current.mentions !== undefined && (
                        <ExtraCard
                            extra="mentions"
                            onRemove={remove('mentions')}
                        >
                            <MentionsField
                                id={`${id}-mentions`}
                                value={current.mentions}
                                onChange={(mentions) => set({ mentions })}
                            />
                        </ExtraCard>
                    )}
                    {current.hashtag !== undefined && (
                        <ExtraCard extra="hashtag" onRemove={remove('hashtag')}>
                            <HashtagField
                                id={`${id}-hashtag`}
                                value={current.hashtag}
                                onChange={(hashtag) => set({ hashtag })}
                            />
                        </ExtraCard>
                    )}
                    {current.location !== undefined && (
                        <ExtraCard
                            extra="location"
                            onRemove={remove('location')}
                        >
                            <LocationField
                                id={`${id}-location`}
                                value={current.location}
                                onChange={(location) => set({ location })}
                            />
                        </ExtraCard>
                    )}
                    {current.poll !== undefined && (
                        <ExtraCard extra="poll" onRemove={remove('poll')}>
                            <PollField
                                id={`${id}-poll`}
                                poll={current.poll}
                                onChange={(poll) => set({ poll })}
                            />
                        </ExtraCard>
                    )}
                </ul>
            )}

            {available.length > 0 && (
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <Button
                            ref={addRef}
                            type="button"
                            variant="outline"
                            size="sm"
                            className="h-11 justify-self-start sm:h-8"
                        >
                            <Plus />
                            {byHand ? 'Add another' : 'Add an extra'}
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent
                        align="start"
                        className="w-56"
                        // After adding an extra, focus lands in its first field, not back here.
                        onCloseAutoFocus={(event) => {
                            const added = focusRef.current;

                            if (added && added !== 'add') {
                                focusRef.current = null;
                                event.preventDefault();
                                sectionRef.current
                                    ?.querySelector<HTMLElement>(
                                        `[data-extra="${added}"] :is(input, textarea)`,
                                    )
                                    ?.focus();
                            }
                        }}
                    >
                        {available.map((key) => {
                            const Icon = EXTRA_ICONS[key];

                            return (
                                <DropdownMenuItem
                                    key={key}
                                    onSelect={() => {
                                        focusRef.current = key;
                                        set(emptyStoryExtra(key));
                                    }}
                                    className="flex items-center gap-2.5 py-2.5"
                                >
                                    <span className="bg-accent text-accent-foreground flex size-7 shrink-0 items-center justify-center rounded-full">
                                        <Icon className="size-3.5" />
                                    </span>
                                    <span className="text-sm font-medium">
                                        {STORY_EXTRA_LABELS[key]}
                                    </span>
                                </DropdownMenuItem>
                            );
                        })}
                    </DropdownMenuContent>
                </DropdownMenu>
            )}

            {byHand && (
                <>
                    <div className="grid gap-2">
                        <span className="flex items-baseline">
                            <Label htmlFor={`${id}-note`}>Placement note</Label>
                            <Counter
                                used={(current.placement_note ?? '').length}
                                limit={STORY_LIMITS.placementNote}
                            />
                        </span>
                        <Textarea
                            id={`${id}-note`}
                            dir="auto"
                            rows={2}
                            value={current.placement_note ?? ''}
                            maxLength={STORY_LIMITS.placementNote}
                            onChange={(event) =>
                                set({ placement_note: event.target.value })
                            }
                            placeholder="Link sticker bottom centre, under the price."
                            className="min-h-16"
                        />
                        <FieldHint>
                            Optional. Where the stickers should sit, for whoever
                            posts it.
                        </FieldHint>
                    </div>

                    <WhoPostsIt
                        id={`${id}-who`}
                        recipient={recipient}
                        onChange={onRecipientChange}
                        options={options}
                        assigneeId={assigneeId}
                        clientName={clientName}
                    />

                    <IfMissedChoice
                        publishPlain={current.if_missed === 'publish_plain'}
                        onChange={(publishPlain) =>
                            set({
                                if_missed: publishPlain
                                    ? 'publish_plain'
                                    : 'fail',
                            })
                        }
                        windowMinutes={options.window_minutes}
                    />
                </>
            )}
        </section>
    );
}
