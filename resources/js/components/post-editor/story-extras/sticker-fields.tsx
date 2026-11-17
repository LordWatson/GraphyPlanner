import { useState } from 'react';
import {
    Counter,
    FieldHint,
} from '@/components/post-editor/story-extras/parts';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { parseHandles, STORY_LIMITS } from '@/lib/story-extras';

const HANDLE = /^[A-Za-z0-9._]{1,30}$/;

/**
 * Mentions are typed as one line ("@acme @bob"); the list is derived from it. The raw text lives
 * here so a trailing space or comma survives while typing.
 */
export function MentionsField({
    id,
    value,
    onChange,
}: {
    id: string;
    value: string[];
    onChange: (handles: string[]) => void;
}) {
    const [text, setText] = useState(() =>
        value.map((handle) => `@${handle}`).join(' '),
    );
    const tooMany = value.length > STORY_LIMITS.mentions;
    const invalid = value.find((handle) => !HANDLE.test(handle));

    return (
        <div className="grid gap-2">
            <Label htmlFor={id}>Accounts to mention</Label>
            <Input
                id={id}
                value={text}
                onChange={(event) => {
                    setText(event.target.value);
                    onChange(parseHandles(event.target.value));
                }}
                placeholder="@handle"
                autoCapitalize="none"
                autoCorrect="off"
                spellCheck={false}
                aria-invalid={tooMany || invalid !== undefined}
                aria-describedby={`${id}-hint`}
            />
            <div id={`${id}-hint`}>
                {tooMany ? (
                    <FieldHint tone="error">
                        A story can mention up to {STORY_LIMITS.mentions}{' '}
                        accounts.
                    </FieldHint>
                ) : invalid !== undefined ? (
                    <FieldHint tone="error">
                        @{invalid} isn’t a valid handle. Use letters, numbers,
                        dots and underscores.
                    </FieldHint>
                ) : (
                    <FieldHint>
                        Up to {STORY_LIMITS.mentions}. Separate them with a
                        space.
                    </FieldHint>
                )}
            </div>
        </div>
    );
}

export function HashtagField({
    id,
    value,
    onChange,
}: {
    id: string;
    value: string;
    onChange: (tag: string) => void;
}) {
    const spaced = /\s/.test(value.trim());

    return (
        <div className="grid gap-2">
            <Label htmlFor={id}>Hashtag</Label>
            <div className="relative">
                <span
                    aria-hidden
                    className="text-muted-foreground pointer-events-none absolute inset-y-0 start-3 flex items-center text-base md:text-sm"
                >
                    #
                </span>
                <Input
                    id={id}
                    value={value}
                    maxLength={STORY_LIMITS.hashtag}
                    onChange={(event) =>
                        onChange(event.target.value.replace(/^#+/, ''))
                    }
                    placeholder="summersale"
                    autoCapitalize="none"
                    autoCorrect="off"
                    spellCheck={false}
                    className="ps-7"
                    aria-invalid={spaced}
                    aria-describedby={`${id}-hint`}
                />
            </div>
            <div id={`${id}-hint`}>
                {spaced ? (
                    <FieldHint tone="error">
                        A hashtag is one word, with no spaces.
                    </FieldHint>
                ) : (
                    <FieldHint>One tag.</FieldHint>
                )}
            </div>
        </div>
    );
}

export function LocationField({
    id,
    value,
    onChange,
}: {
    id: string;
    value: string;
    onChange: (place: string) => void;
}) {
    return (
        <div className="grid gap-2">
            <span className="flex items-baseline">
                <Label htmlFor={id}>Place</Label>
                <Counter used={value.length} limit={STORY_LIMITS.location} />
            </span>
            <Input
                id={id}
                dir="auto"
                value={value}
                maxLength={STORY_LIMITS.location}
                onChange={(event) => onChange(event.target.value)}
                placeholder="Leeds, United Kingdom"
                aria-describedby={`${id}-hint`}
            />
            <div id={`${id}-hint`}>
                <FieldHint>
                    The poster picks the exact place in Instagram.
                </FieldHint>
            </div>
        </div>
    );
}
