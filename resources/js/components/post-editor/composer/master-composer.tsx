import { Link2, MessageCircle } from 'lucide-react';
import type { ReactNode, Ref } from 'react';
import { useState } from 'react';
import { HashtagInput } from '@/components/hashtag-input';
import InputError from '@/components/input-error';
import type { MasterContent } from '@/components/post-editor/composer/composer-parts';
import {
    AddChip,
    CaptionMeter,
    captionClass,
    usageFor,
} from '@/components/post-editor/composer/composer-parts';
import type {
    ChannelState,
    TargetAccountData,
} from '@/components/post-editor/types';
import { EMPTY_CHANNEL } from '@/components/post-editor/types';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { usePlatforms } from '@/lib/platforms';

export type ComposerErrors = Partial<
    Record<'master_caption' | 'link_url' | 'first_comment', string>
>;

/**
 * "All channels": the main caption, hashtags, link and first comment every channel inherits. The
 * meter shows the tightest fit among channels still following the main caption.
 */
export function MasterComposer({
    accounts,
    master,
    onMasterChange,
    channels,
    hashtagSuggestions,
    errors,
    aiPanel,
    captionRef,
}: {
    accounts: TargetAccountData[];
    master: MasterContent;
    onMasterChange: (patch: Partial<MasterContent>) => void;
    channels: Record<number, ChannelState>;
    hashtagSuggestions: string[];
    errors: ComposerErrors;
    aiPanel?: ReactNode;
    captionRef?: Ref<HTMLTextAreaElement>;
}) {
    const registry = usePlatforms();
    const [showLink, setShowLink] = useState(false);
    const [showComment, setShowComment] = useState(false);
    // The tightest fit among channels still following the main caption.
    const worst = accounts
        .filter((a) => (channels[a.id] ?? EMPTY_CHANNEL).caption === null)
        .map((a) => ({
            account: a,
            ...usageFor(a, channels[a.id] ?? EMPTY_CHANNEL, master, registry),
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
                                    showComment && master.firstComment === ''
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
                        <AddChip icon={Link2} onClick={() => setShowLink(true)}>
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
