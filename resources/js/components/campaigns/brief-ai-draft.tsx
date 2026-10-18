import { Sparkles } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import type { SuggestionSection } from '@/components/campaigns/ai-suggestion-sheet';
import { AiSuggestionSheet } from '@/components/campaigns/ai-suggestion-sheet';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { briefSectionLabels, postJson } from '@/lib/campaigns';
import { brief as aiBrief } from '@/routes/campaigns/ai';

type Draft = {
    fields: Record<string, string | string[] | null>;
    flags: Record<string, string[]>;
};

/**
 * Step C-2.2 — "Draft with AI" for the brief. Fetches a suggestion and opens the review sheet;
 * accepted sections fill the editor (lists become one line per item). Saving stays a separate,
 * deliberate click.
 */
export function BriefAiDraft<K extends string>({
    campaignId,
    current,
    onAccept,
}: {
    campaignId: number;
    current: Record<K, string>;
    onAccept: (key: K, value: string) => void;
}) {
    const [loading, setLoading] = useState(false);
    const [sections, setSections] = useState<SuggestionSection[]>([]);
    const [open, setOpen] = useState(false);

    const draft = async () => {
        setLoading(true);
        const result = await postJson<{ draft: Draft }>(
            aiBrief.url(campaignId),
        );
        setLoading(false);

        if (!result.ok) {
            toast.error(result.message);

            return;
        }

        const { fields, flags } = result.data.draft;

        setSections(
            Object.entries(briefSectionLabels).map(([key, label]) => {
                const value = fields[key];

                return {
                    key,
                    label,
                    current: current[key as K] ?? '',
                    suggested: Array.isArray(value)
                        ? value.join('\n')
                        : (value ?? ''),
                    flags: flags[key] ?? [],
                };
            }),
        );
        setOpen(true);
    };

    return (
        <>
            <Button variant="secondary" onClick={draft} disabled={loading}>
                {loading ? <Spinner /> : <Sparkles />}
                {loading ? 'Drafting…' : 'Draft with AI'}
            </Button>
            <AiSuggestionSheet
                open={open}
                onOpenChange={setOpen}
                title="A first draft of the brief"
                description="Built from the campaign’s goal, dates and the client’s Brand Brain. Take what’s useful."
                sections={sections}
                onAccept={(key, value) => onAccept(key as K, value)}
            />
        </>
    );
}
