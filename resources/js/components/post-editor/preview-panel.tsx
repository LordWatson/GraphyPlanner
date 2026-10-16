import { Eye, Maximize2 } from 'lucide-react';
import { useState } from 'react';
import { platformIcon } from '@/components/platform-icon';
import type { AssetData } from '@/components/post-editor/types';
import { SocialPostPreview } from '@/components/social-post-preview';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { cn } from '@/lib/utils';

type PreviewTarget = { id: number; platform: string; handle: string };

function TargetSwitcher({
    targets,
    activeId,
    onSelect,
}: {
    targets: PreviewTarget[];
    activeId: number | null;
    onSelect: (id: number) => void;
}) {
    if (targets.length < 2) {
        return null;
    }

    return (
        <div className="bg-muted flex flex-wrap gap-1 rounded-full p-1">
            {targets.map((target) => {
                const Icon = platformIcon(target.platform);
                const active = target.id === activeId;

                return (
                    <button
                        key={target.id}
                        type="button"
                        onClick={() => onSelect(target.id)}
                        aria-pressed={active}
                        className={cn(
                            'inline-flex min-w-0 items-center gap-1.5 rounded-full px-2.5 py-1 text-xs transition-colors',
                            active
                                ? 'bg-card text-foreground shadow-xs'
                                : 'text-muted-foreground hover:text-foreground',
                        )}
                    >
                        <Icon className="size-3.5 shrink-0" />
                        <span className="truncate">{target.handle}</span>
                    </button>
                );
            })}
        </div>
    );
}

/** The live platform mockup in the editor's right rail, with a fullscreen view. */
export function PreviewPanel({
    targets,
    caption,
    hashtags,
    assets,
    musicName,
    locationName,
}: {
    targets: PreviewTarget[];
    caption: string;
    hashtags: string[];
    assets: AssetData[];
    musicName: string;
    locationName: string;
}) {
    const [activeId, setActiveId] = useState<number | null>(
        targets[0]?.id ?? null,
    );
    const [fullscreen, setFullscreen] = useState(false);
    const active =
        targets.find((target) => target.id === activeId) ?? targets[0] ?? null;

    const preview = active ? (
        <SocialPostPreview
            target={active}
            caption={caption}
            hashtags={hashtags}
            assets={assets}
            musicName={musicName}
            locationName={locationName}
        />
    ) : null;

    return (
        <section className="border-border bg-card rounded-2xl border">
            <div className="border-border/70 flex items-center justify-between gap-2 border-b px-5 py-3">
                <h2 className="flex items-center gap-2 text-[15px] font-semibold">
                    <Eye className="text-muted-foreground size-4" />
                    Preview
                </h2>
                {active && (
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="size-8"
                        onClick={() => setFullscreen(true)}
                        aria-label="Open preview fullscreen"
                    >
                        <Maximize2 className="size-4" />
                    </Button>
                )}
            </div>
            <div className="grid gap-4 p-4">
                <TargetSwitcher
                    targets={targets}
                    activeId={active?.id ?? null}
                    onSelect={setActiveId}
                />
                <div className="flex justify-center">
                    {preview ?? (
                        <p className="text-muted-foreground py-8 text-center text-sm">
                            Pick a channel to see how the post will look.
                        </p>
                    )}
                </div>
                {active && (
                    <p className="text-muted-foreground text-center text-[11px]">
                        An approximation — not pixel-perfect.
                    </p>
                )}
            </div>

            <Dialog open={fullscreen} onOpenChange={setFullscreen}>
                <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                    <DialogHeader>
                        <DialogTitle>
                            Preview{active ? ` — ${active.handle}` : ''}
                        </DialogTitle>
                    </DialogHeader>
                    <TargetSwitcher
                        targets={targets}
                        activeId={active?.id ?? null}
                        onSelect={setActiveId}
                    />
                    <div className="flex justify-center py-2">{preview}</div>
                </DialogContent>
            </Dialog>
        </section>
    );
}
