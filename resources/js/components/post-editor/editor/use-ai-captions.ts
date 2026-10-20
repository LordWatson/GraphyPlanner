import { useState } from 'react';
import { confirmDialog } from '@/components/confirm-dialog';
import { normalizeTag } from '@/components/hashtag-input';
import type { AiCaptionSuggestion } from '@/components/post-editor/ai-caption-suggestions';
import { http } from '@/lib/http';
import aiPosts from '@/routes/posts/ai';

/**
 * Step 2.4 — "Suggest with AI" for the caption. Never overwrites the caption without an explicit
 * click (and a confirm when there's already a caption); the result still has to be saved like any
 * other edit. Step 2.6 — each suggestion's platform policy warnings come along for a human to
 * decide on.
 */
export function useAiCaptions({
    postId,
    caption,
    onApply,
}: {
    postId: number;
    caption: string;
    onApply: (caption: string, hashtags: string[]) => void;
}) {
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [suggestions, setSuggestions] = useState<AiCaptionSuggestion[]>([]);
    const [policyWarnings, setPolicyWarnings] = useState<string[][]>([]);
    const [appliedIndex, setAppliedIndex] = useState<number | null>(null);
    const [expanded, setExpanded] = useState(true);

    const fetchSuggestions = async () => {
        setError(null);
        setLoading(true);

        // Step I-1.7: POST — this calls a paid vendor.
        const result = await http.post<{
            suggestions?: AiCaptionSuggestion[];
            policyWarnings?: string[][];
        }>(aiPosts.captions.url(postId), undefined, {
            rateLimitMessage:
                'That’s a lot of AI in a short time. Give it a minute.',
        });
        setLoading(false);

        if (!result.ok) {
            setError(result.message);

            return;
        }

        setSuggestions(
            Array.isArray(result.data.suggestions)
                ? result.data.suggestions
                : [],
        );
        setPolicyWarnings(
            Array.isArray(result.data.policyWarnings)
                ? result.data.policyWarnings
                : [],
        );
        setAppliedIndex(null);
        setExpanded(true);
    };

    const apply = async (suggestion: AiCaptionSuggestion, index: number) => {
        if (
            caption.trim() &&
            !(await confirmDialog({
                title: 'Replace your caption?',
                description:
                    'Your current caption and hashtags will be swapped for this AI suggestion. Nothing is saved until you save the post.',
                confirmLabel: 'Replace caption',
            }))
        ) {
            return;
        }

        // AI suggestions come back as "#demo"; the hashtag field stores bare tags.
        onApply(suggestion.caption, [
            ...new Set(
                (suggestion.hashtags ?? []).map(normalizeTag).filter(Boolean),
            ),
        ]);
        setAppliedIndex(index);
        setExpanded(false);
    };

    return {
        loading,
        error,
        suggestions,
        policyWarnings,
        appliedIndex,
        expanded,
        toggleExpanded: () => setExpanded((current) => !current),
        fetchSuggestions,
        apply,
    };
}

export type AiCaptions = ReturnType<typeof useAiCaptions>;
