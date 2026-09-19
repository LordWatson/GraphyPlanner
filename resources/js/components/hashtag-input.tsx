import { Hash, X } from 'lucide-react';
import { useMemo, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';

/**
 * Normalizes a raw hashtag as typed by the user: trims whitespace and strips any leading "#"s,
 * since some users type "#launch" and others just "launch" (the server also normalizes this on
 * save, but doing it client-side keeps the chips/suggestions consistent as you type).
 */
function normalizeTag(raw: string): string {
    return raw.trim().replace(/^#+/, '');
}

/**
 * Instagram-style hashtag picker: type to see matching suggestions (from `suggestions`, e.g. the
 * brand's "always use" hashtags plus tags used on the client's other posts), click/Enter to add
 * one, or just press Enter/comma to add whatever was typed as a new tag. Existing tags render as
 * removable chips above the input.
 */
export function HashtagInput({
    value,
    onChange,
    suggestions = [],
    placeholder = 'Start typing a hashtag…',
    id,
}: {
    value: string[];
    onChange: (tags: string[]) => void;
    suggestions?: string[];
    placeholder?: string;
    id?: string;
}) {
    const [inputValue, setInputValue] = useState('');
    const [highlighted, setHighlighted] = useState(0);
    const [isFocused, setIsFocused] = useState(false);

    const query = normalizeTag(inputValue).toLowerCase();

    const matches = useMemo(() => {
        if (query.length === 0) {
            return [];
        }

        return suggestions
            .filter((tag) => !value.includes(tag))
            .filter((tag) => tag.toLowerCase().includes(query))
            .slice(0, 8);
    }, [suggestions, value, query]);

    const showDropdown = isFocused && matches.length > 0;

    const addTag = (raw: string) => {
        const tag = normalizeTag(raw);

        if (tag.length === 0 || value.includes(tag)) {
            setInputValue('');
            return;
        }

        onChange([...value, tag]);
        setInputValue('');
        setHighlighted(0);
    };

    const removeTag = (tag: string) => {
        onChange(value.filter((t) => t !== tag));
    };

    const handleKeyDown = (e: React.KeyboardEvent<HTMLInputElement>) => {
        if (e.key === 'Enter' || e.key === ',') {
            e.preventDefault();

            if (showDropdown && matches[highlighted]) {
                addTag(matches[highlighted]);
            } else if (inputValue.trim().length > 0) {
                addTag(inputValue);
            }

            return;
        }

        if (e.key === 'ArrowDown' && showDropdown) {
            e.preventDefault();
            setHighlighted((current) => Math.min(current + 1, matches.length - 1));
            return;
        }

        if (e.key === 'ArrowUp' && showDropdown) {
            e.preventDefault();
            setHighlighted((current) => Math.max(current - 1, 0));
            return;
        }

        if (e.key === 'Backspace' && inputValue.length === 0 && value.length > 0) {
            removeTag(value[value.length - 1]);
        }
    };

    return (
        <div className="relative">
            <div className="flex flex-wrap items-center gap-1.5 rounded-md border border-input bg-transparent px-2 py-1.5 focus-within:border-ring focus-within:ring-[3px] focus-within:ring-ring/50 dark:bg-input/30">
                {value.map((tag) => (
                    <Badge key={tag} variant="secondary" className="gap-1 pr-1">
                        <Hash className="size-3" />
                        {tag}
                        <button
                            type="button"
                            onClick={() => removeTag(tag)}
                            className="rounded-full p-0.5 hover:bg-muted-foreground/20"
                            aria-label={`Remove #${tag}`}
                        >
                            <X className="size-3" />
                        </button>
                    </Badge>
                ))}
                <Input
                    id={id}
                    value={inputValue}
                    onChange={(e) => {
                        setInputValue(e.target.value);
                        setHighlighted(0);
                    }}
                    onKeyDown={handleKeyDown}
                    onFocus={() => setIsFocused(true)}
                    onBlur={() => {
                        // Delay so a click on a suggestion registers before the dropdown unmounts.
                        setTimeout(() => setIsFocused(false), 150);
                    }}
                    placeholder={value.length === 0 ? placeholder : ''}
                    className="h-7 min-w-[8rem] flex-1 border-none bg-transparent p-0 shadow-none focus-visible:ring-0 dark:bg-transparent"
                />
            </div>

            {showDropdown && (
                <ul className="absolute z-10 mt-1 max-h-56 w-full overflow-auto rounded-md border border-border bg-popover p-1 text-sm shadow-md">
                    {matches.map((tag, index) => (
                        <li key={tag}>
                            <button
                                type="button"
                                onMouseDown={(e) => e.preventDefault()}
                                onClick={() => addTag(tag)}
                                className={cn(
                                    'flex w-full items-center gap-1.5 rounded-sm px-2 py-1.5 text-left',
                                    index === highlighted ? 'bg-accent text-accent-foreground' : 'hover:bg-accent/60',
                                )}
                            >
                                <Hash className="size-3.5 text-muted-foreground" />
                                {tag}
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
