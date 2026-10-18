import { Form } from '@inertiajs/react';
import { Lock, MessagesSquare, Send } from 'lucide-react';
import { Fragment } from 'react';
import type { CampaignComment } from '@/components/campaigns/types';
import InputError from '@/components/input-error';
import type { MentionableUser } from '@/components/mention-textarea';
import { MentionTextarea } from '@/components/mention-textarea';
import { EmptyState } from '@/components/page';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { formatDateTime } from '@/lib/dates';
import { cn } from '@/lib/utils';

type FormDefinition = { action: string; method: 'post' };

/** Renders `@[Name](id)` mention tokens as a bold "@Name". */
function Body({ text }: { text: string }) {
    const parts = text.split(/(@\[[^\]]+\]\(\d+\))/g);

    return (
        <p className="text-[14px] leading-relaxed text-pretty whitespace-pre-line">
            {parts.map((part, index) => {
                const match = part.match(/^@\[([^\]]+)\]\(\d+\)$/);

                return match ? (
                    <strong key={index} className="text-primary font-semibold">
                        @{match[1]}
                    </strong>
                ) : (
                    <Fragment key={index}>{part}</Fragment>
                );
            })}
        </p>
    );
}

/**
 * Step C-5.3 — the campaign's one conversation, shared by the studio (Overview tab) and the client
 * portal. Studio messages can be marked internal (a lock and a tinted card), and those never reach
 * the portal — the server filters them, this only styles them. Client messages sit on the lilac
 * tint so the studio can tell sides apart at a glance.
 */
export function CampaignConversation({
    comments,
    form,
    mentionables = [],
    allowInternal = false,
    emptyHint,
    canPost = true,
}: {
    comments: CampaignComment[];
    form: FormDefinition;
    mentionables?: MentionableUser[];
    allowInternal?: boolean;
    emptyHint: string;
    canPost?: boolean;
}) {
    return (
        <div id="conversation" className="flex scroll-mt-24 flex-col gap-4">
            {comments.length === 0 ? (
                <EmptyState
                    compact
                    icon={MessagesSquare}
                    title="Nothing said yet."
                    description={emptyHint}
                />
            ) : (
                <ol className="flex max-h-[28rem] flex-col gap-3 overflow-y-auto pr-1">
                    {comments.map((comment) => (
                        <li
                            key={comment.id}
                            className={cn(
                                'rounded-xl border px-4 py-3',
                                comment.internal_only
                                    ? 'border-warning/25 bg-warning/5'
                                    : comment.is_client
                                      ? 'border-lilac/50 bg-lilac/15'
                                      : 'border-border bg-background',
                            )}
                        >
                            <div className="mb-1 flex flex-wrap items-center gap-x-2 gap-y-0.5">
                                <span className="text-[13px] font-semibold">
                                    {comment.author}
                                </span>
                                {comment.is_client && (
                                    <span className="eyebrow text-muted-foreground">
                                        Client
                                    </span>
                                )}
                                {comment.internal_only && (
                                    <span className="text-warning inline-flex items-center gap-1 text-[11px] font-medium">
                                        <Lock className="size-3" />
                                        Internal
                                    </span>
                                )}
                                <span className="text-muted-foreground ml-auto font-mono text-[11px]">
                                    {formatDateTime(comment.created_at)}
                                </span>
                            </div>
                            <Body text={comment.body} />
                        </li>
                    ))}
                </ol>
            )}

            {canPost && (
                <Form
                    action={form.action}
                    method={form.method}
                    options={{ preserveScroll: true }}
                    resetOnSuccess
                    className="flex flex-col gap-2.5"
                >
                    {({ processing, errors }) => (
                        <>
                            <Label
                                htmlFor="campaign-comment"
                                className="sr-only"
                            >
                                Message
                            </Label>
                            <MentionTextarea
                                id="campaign-comment"
                                name="body"
                                rows={3}
                                users={mentionables}
                                placeholder={
                                    mentionables.length > 0
                                        ? 'Write a message. Type @ to mention someone.'
                                        : 'Write a message.'
                                }
                            />
                            <InputError message={errors.body} />
                            <div className="flex flex-wrap items-center justify-between gap-3">
                                {allowInternal ? (
                                    <label className="text-muted-foreground flex items-center gap-2 text-[13px]">
                                        <Checkbox
                                            name="internal_only"
                                            value="1"
                                        />
                                        Internal note (the client won’t see it)
                                    </label>
                                ) : (
                                    <span />
                                )}
                                <Button
                                    type="submit"
                                    size="sm"
                                    disabled={processing}
                                >
                                    <Send />
                                    Send
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            )}
        </div>
    );
}
