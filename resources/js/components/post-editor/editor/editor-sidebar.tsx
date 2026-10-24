import { ArrowRight } from 'lucide-react';
import { usePostEditorContext } from '@/components/post-editor/editor/editor-context';
import { PreviewPanel } from '@/components/post-editor/preview-panel';
import type { ChecklistItem } from '@/components/post-editor/types';
import { cn } from '@/lib/utils';

/**
 * A compact "how ready is this" nudge for the preview rail: the count, a thin progress bar, and a
 * jump to the Review & send step where the full checklist and the send actions live. The full
 * `ReadinessChecklist` is not repeated here — this is just the glance.
 */
function ReadinessSummary({ items }: { items: ChecklistItem[] }) {
    const { nav } = usePostEditorContext();
    const required = items.filter((item) => !item.soft);
    const passed = required.filter((item) => item.passed).length;
    const total = required.length;
    const allPassed = total > 0 && passed === total;
    const nextOpen = items.find((item) => !item.passed && !item.soft);

    return (
        <button
            type="button"
            onClick={() => nav.goToStep('review')}
            className="group border-border bg-card focus-visible:ring-ring/50 rounded-2xl border p-4 text-start shadow-sm outline-none focus-visible:ring-[3px]"
        >
            <div className="flex items-baseline justify-between gap-2">
                <span className="text-[15px] font-semibold">
                    {allPassed ? 'Ready to send.' : 'Almost ready'}
                </span>
                <span
                    className={cn(
                        'font-mono text-xs tabular-nums',
                        allPassed ? 'text-success' : 'text-muted-foreground',
                    )}
                >
                    {passed}/{total}
                </span>
            </div>
            <div
                className="bg-muted mt-3 flex h-1 gap-0.5 overflow-hidden rounded-full"
                aria-hidden
            >
                {required.map((item) => (
                    <span
                        key={item.key}
                        className={cn(
                            'h-full flex-1 transition-colors',
                            item.passed ? 'bg-success' : 'bg-transparent',
                        )}
                    />
                ))}
            </div>
            <span className="text-muted-foreground group-hover:text-foreground mt-3 flex items-center gap-1.5 text-[13px] transition-colors">
                {allPassed
                    ? 'Review & send'
                    : (nextOpen?.label ?? 'Review & send')}
                <ArrowRight className="size-3.5 shrink-0" />
            </span>
        </button>
    );
}

/**
 * The editor's right rail: the live per-channel preview with framing tools — the constant anchor
 * while you build — and, on the build steps, a compact readiness nudge into Review & send. Viewers
 * without edit rights see the saved post.
 */
export function EditorSidebar() {
    const { editor, nav, page } = usePostEditorContext();
    const { post, can } = page;
    const { state, view } = editor;
    const editable = can.update;

    return (
        <aside className="grid min-w-0 gap-5 lg:sticky lg:top-20">
            {editable && nav.activeTab !== 'review' && (
                <ReadinessSummary items={view.checklistItems} />
            )}
            <div
                ref={nav.sectionRef('preview')}
                className={cn('scroll-mt-24 rounded-2xl', nav.flash('preview'))}
            >
                <PreviewPanel
                    targets={view.previewTargets}
                    activeId={editable ? nav.previewId : undefined}
                    onActiveChange={nav.changePreview}
                    caption={
                        editable ? state.caption : (post.master_caption ?? '')
                    }
                    hashtags={editable ? state.hashtags : post.hashtags}
                    assets={editable ? view.selectedAssets : post.assets}
                    musicName={
                        editable
                            ? state.musicName
                            : ((post.music?.name as string) ?? '')
                    }
                    locationName={
                        editable
                            ? state.locationName
                            : ((post.location?.name as string) ?? '')
                    }
                    mediaFormat={
                        editable ? state.mediaFormat : post.media_format
                    }
                    crops={editable ? view.saved.crops : editor.initial.crops}
                    formatOptions={page.mediaFormatOptions}
                    editable={editable}
                    onMediaFormatChange={(mediaFormat) =>
                        editor.update({ mediaFormat })
                    }
                    onCropsChange={(next) =>
                        editor.update({ crops: { ...state.crops, ...next } })
                    }
                />
            </div>
        </aside>
    );
}
