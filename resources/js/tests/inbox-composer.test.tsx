import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vite-plus/test';

const { post } = vi.hoisted(() => ({
    post: vi.fn((..._args: unknown[]) =>
        Promise.resolve({ ok: true, status: 200, data: { conversation: {} } }),
    ),
}));

vi.mock('@/lib/http', () => ({ http: { post } }));

import { Composer, type ConversationDetail } from '@/pages/inbox/index';

const detail = {
    id: 7,
    client: { id: 1, name: 'Acme' },
    platform: 'instagram',
    platform_label: 'Instagram',
    author: { name: 'Ada', handle: '@ada', avatar_url: null },
    sentiment: 'question',
    status: 'open',
    assignee: null,
    is_reply_needed: true,
    snippet: 'Do you ship to Dubai?',
    last_message_at: null,
    last_inbound_at: null,
    snoozed_until: null,
    permalink: null,
    resolved_at: null,
    first_response_at: null,
    post: null,
    messages: [],
    can: { reply: true, act: true },
} as ConversationDetail;

/**
 * Phase M-9.3 — the inbox reply composer. The product guarantee is that a reply only ever leaves on
 * an explicit human Send: the button is disabled until there is text, and nothing is posted on render
 * or while typing — only on the click.
 */
describe('inbox Composer', () => {
    beforeEach(() => post.mockClear());

    it('disables Send until the reply has content and never posts on its own', () => {
        render(
            <Composer
                detail={detail}
                aiEnabled={false}
                savedReplies={[]}
                onChanged={vi.fn()}
            />,
        );

        const send = screen.getByRole('button', { name: /send/i });
        expect(send).toBeDisabled();

        fireEvent.change(screen.getByRole('textbox'), {
            target: { value: 'Yes, we ship to Dubai!' },
        });
        expect(send).toBeEnabled();

        // Still nothing sent — a human has to click.
        expect(post).not.toHaveBeenCalled();
    });

    it('sends only when the human clicks Send', () => {
        render(
            <Composer
                detail={detail}
                aiEnabled={false}
                savedReplies={[]}
                onChanged={vi.fn()}
            />,
        );

        fireEvent.change(screen.getByRole('textbox'), {
            target: { value: 'On brand reply' },
        });
        fireEvent.click(screen.getByRole('button', { name: /send/i }));

        expect(post).toHaveBeenCalledTimes(1);
        expect(post.mock.calls[0][0]).toContain('/inbox/7/reply');
    });
});
