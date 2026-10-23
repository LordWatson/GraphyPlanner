import { useState } from 'react';
import { confirmDialog } from '@/components/confirm-dialog';
import { normalizeTag } from '@/components/hashtag-input';
import type {
    ChannelState,
    TargetAccountData,
} from '@/components/post-editor/types';
import { EMPTY_CHANNEL } from '@/components/post-editor/types';
import { http } from '@/lib/http';
import aiPosts from '@/routes/posts/ai';

type Variant = {
    caption: string;
    hashtags: string[];
    firstComment: string | null;
    platform_label: string;
    caption_limit: number;
};

/**
 * Step B-6.4 — "Repurpose for each platform". Asks the server for a native variant per target
 * platform and drops each into the matching channel's override fields. Like the caption helper, it
 * only changes unsaved editor state (with a confirm when channels are already customised); nothing
 * is written until the post is saved.
 */
export function useAiRepurpose({
    postId,
    accounts,
    channels,
    setChannel,
    anyCustomized,
}: {
    postId: number;
    accounts: TargetAccountData[];
    channels: Record<number, ChannelState>;
    setChannel: (accountId: number, next: ChannelState) => void;
    anyCustomized: boolean;
}) {
    const [loading, setLoading] = useState(false);

    const repurpose = async () => {
        if (
            anyCustomized &&
            !(await confirmDialog({
                title: 'Repurpose every channel?',
                description:
                    'We’ll rewrite each platform’s caption to suit it, replacing any per-channel text you’ve set. Nothing is saved until you save the post.',
                confirmLabel: 'Repurpose',
            }))
        ) {
            return;
        }

        setLoading(true);

        const result = await http.post<{ variants: Record<string, Variant> }>(
            aiPosts.repurpose.url(postId),
            undefined,
            {
                toastOnError: true,
                rateLimitMessage:
                    'That’s a lot of AI in a short time. Give it a minute.',
            },
        );

        setLoading(false);

        if (!result.ok) {
            return;
        }

        const variants = result.data.variants ?? {};
        let applied = 0;

        for (const account of accounts) {
            const variant = variants[account.platform];

            if (!variant) {
                continue;
            }

            setChannel(account.id, {
                ...(channels[account.id] ?? EMPTY_CHANNEL),
                caption: variant.caption,
                hashtags: [
                    ...new Set(
                        (variant.hashtags ?? [])
                            .map(normalizeTag)
                            .filter(Boolean),
                    ),
                ],
                firstComment: variant.firstComment,
            });
            applied++;
        }

        return applied;
    };

    return { loading, repurpose };
}
