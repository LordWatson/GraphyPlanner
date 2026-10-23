import { ChevronDown, Sparkles } from 'lucide-react';
import { ImageStudio } from '@/components/image-studio/image-studio';
import type { ImageStudioData } from '@/components/image-studio/types';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';

/**
 * Step I-4.6 / M-1.6 (extracted M-5.3) — the editor's collapsible AI Image Studio section. Pulled out
 * of `edit.tsx` so the orchestrator stays under the editor line budget.
 */
export function ImageStudioSection({ data }: { data: ImageStudioData }) {
    const imageCount = (data.generations ?? []).reduce(
        (sum, generation) => sum + generation.variants.length,
        0,
    );

    return (
        <Collapsible
            id="image-studio"
            className="border-border bg-card scroll-mt-20 rounded-2xl border shadow-sm"
        >
            <CollapsibleTrigger className="group hover:bg-muted/40 focus-visible:ring-ring/50 flex w-full items-center gap-4 rounded-2xl p-5 text-start transition-colors outline-none focus-visible:ring-[3px]">
                <span className="bg-accent text-primary flex size-11 shrink-0 items-center justify-center rounded-full">
                    <Sparkles className="size-5" />
                </span>
                <span className="grid min-w-0 flex-1 gap-0.5">
                    <span className="flex flex-wrap items-center gap-x-2 gap-y-0.5">
                        <span className="font-display text-lg leading-tight">
                            Make it{' '}
                            <em className="text-primary not-italic">visual</em>.
                        </span>
                        {imageCount > 0 && (
                            <span className="eyebrow text-muted-foreground">
                                {imageCount} image
                                {imageCount === 1 ? '' : 's'}
                            </span>
                        )}
                    </span>
                    <span className="text-muted-foreground text-[13px] text-pretty">
                        Generate on-brand images for this post with AI —
                        art-directed from the client's brand.
                    </span>
                </span>
                <span className="border-border text-muted-foreground group-hover:border-primary/40 group-hover:text-primary flex size-9 shrink-0 items-center justify-center rounded-full border transition-colors">
                    <ChevronDown className="size-4 transition-transform group-data-[state=open]:rotate-180" />
                    <span className="sr-only">Open the image studio</span>
                </span>
            </CollapsibleTrigger>
            <CollapsibleContent className="border-border/70 border-t p-5 pt-6">
                <ImageStudio data={data} hideHeader />
            </CollapsibleContent>
        </Collapsible>
    );
}
