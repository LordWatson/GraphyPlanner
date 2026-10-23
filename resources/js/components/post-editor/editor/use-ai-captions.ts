import { useRef, useState } from 'react';
import { confirmDialog } from '@/components/confirm-dialog';
import { normalizeTag } from '@/components/hashtag-input';
import type { AiCaptionSuggestion } from '@/components/post-editor/ai-caption-suggestions';
import { cancelAiTextJob, runAiTextJob } from '@/lib/ai-text-job';
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
    const [brandFit, setBrandFit] = useState<
        { score: number; reason: string }[]
    >([]);
    const [appliedIndex, setAppliedIndex] = useState<number | null>(null);
    const [expanded, setExpanded] = useState(true);
    const requestId = useRef<string | null>(null);

    // Step M-7.2: the call runs on the queue and we poll it, so a slow vendor never blocks the
    // editor or trips a gateway timeout. `cancel()` stops the in-flight run.
    const fetchSuggestions = async () => {
        setError(null);
        setLoading(true);

        const result = await runAiTextJob<{
            suggestions?: AiCaptionSuggestion[];
            policyWarnings?: string[][];
            brandFit?: { score: number; reason: string }[];
        }>(
            aiPosts.captions.url(postId),
            {},
            {
                onQueued: (id) => {
                    requestId.current = id;
                },
                rateLimitMessage:
                    'That’s a lot of AI in a short time. Give it a minute.',
            },
        );

        setLoading(false);
        requestId.current = null;

        if (!result.ok) {
            // A user-initiated cancel isn't an error to shout about.
            setError(result.cancelled ? null : result.message);

            return;
        }

        setSuggestions(
            Array.isArray(result.result.suggestions)
                ? result.result.suggestions
                : [],
        );
        setPolicyWarnings(
            Array.isArray(result.result.policyWarnings)
                ? result.result.policyWarnings
                : [],
        );
        setBrandFit(
            Array.isArray(result.result.brandFit) ? result.result.brandFit : [],
        );
        setAppliedIndex(null);
        setExpanded(true);
    };

    const cancel = () => {
        if (requestId.current) {
            cancelAiTextJob(requestId.current);
        }
        requestId.current = null;
        setLoading(false);
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
        brandFit,
        appliedIndex,
        expanded,
        toggleExpanded: () => setExpanded((current) => !current),
        fetchSuggestions,
        cancel,
        apply,
    };
}

export type AiCaptions = ReturnType<typeof useAiCaptions>;
