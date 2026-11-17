import {
    Counter,
    FieldHint,
} from '@/components/post-editor/story-extras/parts';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { isHttpsUrl, STORY_LIMITS } from '@/lib/story-extras';

type Link = { url: string; text: string };

/**
 * The link sticker: the address the poster pastes, and the optional text on the sticker. Kept
 * apart from the channel's own link, which feeds the caption — but one tap copies it across.
 */
export function LinkField({
    id,
    link,
    onChange,
    postLink,
}: {
    id: string;
    link: Link;
    onChange: (link: Link) => void;
    /** The link this channel would otherwise carry (its own, else the post's). */
    postLink: string;
}) {
    const url = link.url.trim();
    const invalid = url !== '' && !isHttpsUrl(url);

    return (
        <div className="grid gap-3 sm:grid-cols-[minmax(0,1.6fr)_minmax(0,1fr)]">
            <div className="grid content-start gap-2">
                <span className="flex flex-wrap items-baseline justify-between gap-x-3">
                    <Label htmlFor={`${id}-url`}>Link</Label>
                    {postLink !== '' && postLink !== url && (
                        <Button
                            type="button"
                            variant="link"
                            className="h-auto min-h-11 p-0 text-xs sm:min-h-0"
                            onClick={() =>
                                onChange({ url: postLink, text: link.text })
                            }
                        >
                            Use the post’s link
                        </Button>
                    )}
                </span>
                <Input
                    id={`${id}-url`}
                    type="url"
                    inputMode="url"
                    autoCapitalize="none"
                    autoCorrect="off"
                    spellCheck={false}
                    value={link.url}
                    onChange={(event) =>
                        onChange({ url: event.target.value, text: link.text })
                    }
                    placeholder="https://"
                    aria-invalid={invalid}
                    aria-describedby={`${id}-url-hint`}
                />
                <div id={`${id}-url-hint`}>
                    {invalid ? (
                        <FieldHint tone="error">
                            Enter a full link, starting with https://.
                        </FieldHint>
                    ) : (
                        <FieldHint>
                            Copied for the poster to paste into the link
                            sticker.
                        </FieldHint>
                    )}
                </div>
            </div>
            <div className="grid content-start gap-2">
                <span className="flex items-baseline">
                    <Label htmlFor={`${id}-text`}>Sticker text</Label>
                    <Counter
                        used={link.text.length}
                        limit={STORY_LIMITS.linkText}
                    />
                </span>
                <Input
                    id={`${id}-text`}
                    dir="auto"
                    value={link.text}
                    maxLength={STORY_LIMITS.linkText}
                    onChange={(event) =>
                        onChange({ url: link.url, text: event.target.value })
                    }
                    placeholder="Shop now"
                    aria-describedby={`${id}-text-hint`}
                />
                <div id={`${id}-text-hint`}>
                    <FieldHint>Optional.</FieldHint>
                </div>
            </div>
        </div>
    );
}
