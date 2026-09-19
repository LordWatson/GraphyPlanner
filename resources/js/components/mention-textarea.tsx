import { useMemo, useRef, useState } from 'react';
import { AtSign } from 'lucide-react';
import { cn } from '@/lib/utils';

export type MentionableUser = { id: number; name: string };

/**
 * Renders the mention tokens (`@[Name](id)`) embedded in a comment body as highlighted `@Name`
 * spans, leaving the rest of the text untouched. Used wherever a stored comment body is
 * displayed (the editor's and portal's comment lists) so mentions read the way they were typed.
 */
export function renderCommentBody(body: string): React.ReactNode[] {
    const pattern = /@\[([^\]]+)\]\(\d+\)/g;
    const nodes: React.ReactNode[] = [];
    let lastIndex = 0;
    let match: RegExpExecArray | null;
    let key = 0;

    while ((match = pattern.exec(body)) !== null) {
        if (match.index > lastIndex) {
            nodes.push(body.slice(lastIndex, match.index));
        }

        nodes.push(
            <span key={`mention-${key++}`} className="font-medium text-primary">
                @{match[1]}
            </span>,
        );

        lastIndex = pattern.lastIndex;
    }

    if (lastIndex < body.length) {
        nodes.push(body.slice(lastIndex));
    }

    return nodes;
}

/**
 * A comment textarea with Slack/GitHub-style `@` mention autocomplete: typing `@` followed by
 * characters filters `users` by name, and picking one inserts an `@[Name](id)` token at the
 * cursor. The server (`CommentMentionParser`) parses these tokens back out to resolve who to
 * notify — this component never sends raw user IDs separately, keeping the comment `body` field
 * self-contained for the existing `StorePostCommentRequest`/`CreatePostCommentAction` flow.
 *
 * Deliberately left as an *uncontrolled* textarea (like the plain `<textarea>` it replaces) so it
 * keeps working with Inertia's `Form` `resetOnSuccess` — mention insertion writes straight to the
 * DOM node via a native input event instead of taking over the value as React state.
 */
export function MentionTextarea({
    name,
    users,
    placeholder,
    rows = 2,
    id,
    defaultValue,
}: {
    name: string;
    users: MentionableUser[];
    placeholder?: string;
    rows?: number;
    id?: string;
    defaultValue?: string;
}) {
    const textareaRef = useRef<HTMLTextAreaElement>(null);
    const [highlighted, setHighlighted] = useState(0);
    const [mentionStart, setMentionStart] = useState<number | null>(null);
    const [query, setQuery] = useState('');

    const matches = useMemo(() => {
        if (mentionStart === null) {
            return [];
        }

        return users.filter((u) => u.name.toLowerCase().includes(query.toLowerCase())).slice(0, 8);
    }, [users, query, mentionStart]);

    const showDropdown = matches.length > 0;

    /**
     * Recompute whether the caret currently sits inside an in-progress `@mention` (an `@`
     * followed only by non-whitespace up to the caret), so the dropdown tracks typing/deleting
     * and closes as soon as a space, newline, or another `@` breaks the token.
     */
    const syncMentionState = () => {
        const el = textareaRef.current;

        if (!el) {
            return;
        }

        const uptoCaret = el.value.slice(0, el.selectionStart ?? 0);
        const at = uptoCaret.lastIndexOf('@');

        if (at === -1 || /\s/.test(uptoCaret.slice(at + 1))) {
            setMentionStart(null);
            return;
        }

        setMentionStart(at);
        setQuery(uptoCaret.slice(at + 1));
        setHighlighted(0);
    };

    const selectUser = (user: MentionableUser) => {
        const el = textareaRef.current;

        if (!el || mentionStart === null) {
            return;
        }

        const caret = el.selectionStart ?? el.value.length;
        const token = `@[${user.name}](${user.id}) `;
        const next = el.value.slice(0, mentionStart) + token + el.value.slice(caret);

        // Use the native value setter + a real `input` event so React/Inertia's uncontrolled
        // form handling (and any other listeners) see the change exactly as if the user typed it.
        const setValue = Object.getOwnPropertyDescriptor(window.HTMLTextAreaElement.prototype, 'value')?.set;
        setValue?.call(el, next);
        el.dispatchEvent(new Event('input', { bubbles: true }));

        setMentionStart(null);

        requestAnimationFrame(() => {
            const cursor = mentionStart + token.length;
            el.setSelectionRange(cursor, cursor);
            el.focus();
        });
    };

    const handleKeyDown = (e: React.KeyboardEvent<HTMLTextAreaElement>) => {
        if (!showDropdown) {
            return;
        }

        if (e.key === 'ArrowDown') {
            e.preventDefault();
            setHighlighted((current) => Math.min(current + 1, matches.length - 1));
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            setHighlighted((current) => Math.max(current - 1, 0));
        } else if (e.key === 'Enter' || e.key === 'Tab') {
            e.preventDefault();
            selectUser(matches[highlighted]);
        } else if (e.key === 'Escape') {
            setMentionStart(null);
        }
    };

    return (
        <div className="relative">
            <textarea
                ref={textareaRef}
                id={id}
                name={name}
                rows={rows}
                defaultValue={defaultValue}
                placeholder={placeholder}
                onChange={syncMentionState}
                onKeyUp={syncMentionState}
                onClick={syncMentionState}
                onKeyDown={handleKeyDown}
                onBlur={() => {
                    // Delay so a click on a suggestion registers before the dropdown unmounts.
                    setTimeout(() => setMentionStart(null), 150);
                }}
                className="border-input dark:bg-input/30 flex w-full rounded-md border bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px]"
            />

            {showDropdown && (
                <ul className="absolute z-10 mt-1 max-h-56 w-full max-w-xs overflow-auto rounded-md border border-border bg-popover p-1 text-sm shadow-md">
                    {matches.map((user, index) => (
                        <li key={user.id}>
                            <button
                                type="button"
                                onMouseDown={(e) => e.preventDefault()}
                                onClick={() => selectUser(user)}
                                className={cn(
                                    'flex w-full items-center gap-1.5 rounded-sm px-2 py-1.5 text-left',
                                    index === highlighted ? 'bg-accent text-accent-foreground' : 'hover:bg-accent/60',
                                )}
                            >
                                <AtSign className="size-3.5 text-muted-foreground" />
                                {user.name}
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
