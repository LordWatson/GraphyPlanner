import { Link as LinkIcon, Sparkles, X } from 'lucide-react';
import { useState } from 'react';
import { CampaignSwatch } from '@/components/campaigns/campaign-chip';
import type { CampaignChipData } from '@/components/campaigns/types';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

export type CampaignOption = CampaignChipData & {
    status: string;
    hashtags: string[];
    link: string | null;
};

const NONE = '__none';

function dismissKey(postId: number, campaignId: string) {
    return `campaign-defaults-dismissed:${postId}:${campaignId}`;
}

function readDismissed(postId: number, campaignId: string): boolean {
    try {
        return (
            window.localStorage.getItem(dismissKey(postId, campaignId)) === '1'
        );
    } catch {
        return false;
    }
}

/**
 * Steps C-1.3 / C-4.2 — the post's campaign (only the client's open campaigns are offered), and,
 * once one is picked, a dismissible offer of its defaults: the campaign hashtags the post is
 * missing and its link with UTM parameters. Accepting changes the unsaved caption/hashtags only;
 * nothing is applied silently.
 */
export function CampaignField({
    postId,
    value,
    options,
    caption,
    hashtags,
    error,
    onChange,
    onAddHashtags,
    onAddLink,
}: {
    postId: number;
    value: string;
    options: CampaignOption[];
    caption: string;
    hashtags: string[];
    error?: string;
    onChange: (campaignId: string) => void;
    onAddHashtags: (tags: string[]) => void;
    onAddLink: (link: string) => void;
}) {
    const selected =
        options.find((option) => String(option.id) === value) ?? null;
    const [dismissed, setDismissed] = useState(() =>
        value ? readDismissed(postId, value) : false,
    );
    const have = new Set(
        hashtags.map((tag) => tag.replace(/^#/, '').toLowerCase()),
    );
    const missingTags = selected
        ? selected.hashtags.filter(
              (tag) => !have.has(tag.replace(/^#/, '').toLowerCase()),
          )
        : [];
    const linkMissing = Boolean(
        selected?.link && !caption.includes(selected.link.split('?')[0]),
    );
    const offer =
        selected && !dismissed && (missingTags.length > 0 || linkMissing);

    const dismiss = () => {
        setDismissed(true);

        try {
            window.localStorage.setItem(dismissKey(postId, value), '1');
        } catch {
            // Storage unavailable: the offer just comes back next visit.
        }
    };

    return (
        <div className="grid gap-3">
            <div className="grid gap-1.5">
                <Label htmlFor="post-campaign">Campaign</Label>
                <Select
                    value={value || NONE}
                    onValueChange={(next) => {
                        const id = next === NONE ? '' : next;
                        onChange(id);
                        setDismissed(id ? readDismissed(postId, id) : false);
                    }}
                >
                    <SelectTrigger id="post-campaign" className="w-full">
                        <SelectValue placeholder="No campaign" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value={NONE}>No campaign</SelectItem>
                        {options.map((option) => (
                            <SelectItem
                                key={option.id}
                                value={String(option.id)}
                            >
                                <span className="flex items-center gap-2">
                                    <CampaignSwatch colour={option.colour} />
                                    {option.code && (
                                        <span className="font-mono text-[11px]">
                                            {option.code}
                                        </span>
                                    )}
                                    {option.name}
                                </span>
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                <InputError message={error} />
            </div>

            {offer && (
                <div className="border-primary/25 bg-accent/50 flex flex-col gap-2.5 rounded-xl border p-3">
                    <div className="flex items-start justify-between gap-2">
                        <span className="flex items-center gap-1.5 text-[13px] font-medium">
                            <Sparkles className="text-primary size-4" />
                            {selected.name} has defaults for its posts.
                        </span>
                        <Button
                            type="button"
                            size="icon"
                            variant="ghost"
                            className="size-7"
                            aria-label="Dismiss"
                            onClick={dismiss}
                        >
                            <X className="size-3.5" />
                        </Button>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {missingTags.length > 0 && (
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                onClick={() => onAddHashtags(missingTags)}
                            >
                                Add{' '}
                                {missingTags
                                    .map((tag) => `#${tag.replace(/^#/, '')}`)
                                    .join(' ')}
                            </Button>
                        )}
                        {linkMissing && selected.link && (
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                onClick={() =>
                                    onAddLink(selected.link as string)
                                }
                            >
                                <LinkIcon />
                                Add the campaign link
                            </Button>
                        )}
                    </div>
                    <p className="text-muted-foreground text-[11px]">
                        Nothing changes until you click, and nothing saves until
                        you save.
                    </p>
                </div>
            )}
        </div>
    );
}
