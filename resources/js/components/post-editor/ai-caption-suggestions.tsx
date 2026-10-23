import { Check, ChevronDown, TriangleAlert } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

export type AiCaptionSuggestion = {
    caption: string;
    hashtags: string[];
    altText: string | null;
    firstComment: string | null;
};

/**
 * Step 2.4/2.6 — AI caption suggestions under the caption field. Nothing is applied without an
 * explicit click, and applying still needs a normal save. Once one is applied the list collapses
 * to a one-line summary that can be re-expanded to compare.
 */
type BrandFit = { score: number; reason: string };

/** A small 0–100 brand-fit badge (Step M-7.4), coloured by how on-brand the caption scores. */
function BrandFitBadge({ fit }: { fit: BrandFit }) {
    const variant =
        fit.score >= 75 ? 'success' : fit.score >= 50 ? 'secondary' : 'outline';

    return (
        <Badge
            variant={variant}
            className="shrink-0 tabular-nums"
            title={fit.reason}
        >
            {fit.score}/100 on-brand
        </Badge>
    );
}

export function AiCaptionSuggestions({
    suggestions,
    policyWarnings,
    brandFit,
    appliedIndex,
    expanded,
    onToggleExpanded,
    onApply,
}: {
    suggestions: AiCaptionSuggestion[];
    policyWarnings: string[][];
    brandFit: BrandFit[];
    appliedIndex: number | null;
    expanded: boolean;
    onToggleExpanded: () => void;
    onApply: (suggestion: AiCaptionSuggestion, index: number) => void;
}) {
    if (suggestions.length === 0) {
        return null;
    }

    return (
        <div className="border-border bg-muted/30 grid gap-2 rounded-xl border p-3">
            <button
                type="button"
                onClick={onToggleExpanded}
                className="flex items-center justify-between gap-2 text-left"
            >
                <span className="eyebrow text-muted-foreground">
                    AI suggestions
                    {appliedIndex !== null && ' — applied'}
                </span>
                <ChevronDown
                    className={cn(
                        'text-muted-foreground size-4 transition-transform',
                        expanded && 'rotate-180',
                    )}
                />
            </button>

            {!expanded && appliedIndex !== null && (
                <p
                    dir="auto"
                    className="text-muted-foreground truncate text-xs"
                >
                    Using: {suggestions[appliedIndex]?.caption}
                </p>
            )}

            {expanded &&
                suggestions.map((suggestion, i) => {
                    const applied = appliedIndex === i;
                    const warnings = policyWarnings[i] ?? [];
                    const fit = brandFit[i];

                    return (
                        <div
                            key={i}
                            className={cn(
                                'bg-card grid gap-2 rounded-lg border p-3',
                                applied ? 'border-primary' : 'border-border',
                            )}
                        >
                            {fit && (
                                <div className="flex items-center justify-between gap-2">
                                    <BrandFitBadge fit={fit} />
                                    <span className="text-muted-foreground truncate text-xs">
                                        {fit.reason}
                                    </span>
                                </div>
                            )}
                            <p
                                dir="auto"
                                className="text-sm whitespace-pre-wrap"
                            >
                                {suggestion.caption}
                            </p>
                            {suggestion.hashtags.length > 0 && (
                                <div className="flex flex-wrap gap-1">
                                    {suggestion.hashtags.map((tag, ti) => (
                                        <Badge
                                            key={ti}
                                            variant="outline"
                                            dir="auto"
                                        >
                                            #{tag.replace(/^#+/, '')}
                                        </Badge>
                                    ))}
                                </div>
                            )}
                            {suggestion.altText && (
                                <p
                                    dir="auto"
                                    className="text-muted-foreground text-xs"
                                >
                                    Alt text: {suggestion.altText}
                                </p>
                            )}
                            {suggestion.firstComment && (
                                <p
                                    dir="auto"
                                    className="text-muted-foreground text-xs"
                                >
                                    First comment: {suggestion.firstComment}
                                </p>
                            )}
                            {warnings.length > 0 && (
                                <ul className="grid gap-0.5">
                                    {warnings.map((warning, wi) => (
                                        <li
                                            key={wi}
                                            className="text-warning flex items-start gap-1.5 text-xs"
                                        >
                                            <TriangleAlert className="mt-0.5 size-3 shrink-0" />
                                            {warning}
                                        </li>
                                    ))}
                                </ul>
                            )}
                            <div>
                                <Button
                                    type="button"
                                    size="sm"
                                    variant={applied ? 'ghost' : 'outline'}
                                    onClick={() => onApply(suggestion, i)}
                                >
                                    {applied && <Check />}
                                    {applied ? 'Applied' : 'Use this caption'}
                                </Button>
                            </div>
                        </div>
                    );
                })}
        </div>
    );
}
