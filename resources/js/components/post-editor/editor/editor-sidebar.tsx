import { usePostEditorContext } from '@/components/post-editor/editor/editor-context';
import { checkSection } from '@/components/post-editor/editor/editor-state';
import { PreviewPanel } from '@/components/post-editor/preview-panel';
import { ReadinessChecklist } from '@/components/post-editor/readiness-checklist';
import { cn } from '@/lib/utils';

/**
 * The editor's right rail: the live readiness checklist (each line jumps to the field that fixes
 * it) and the per-channel preview with framing tools. Viewers without edit rights see the saved
 * post.
 */
export function EditorSidebar() {
    const { editor, nav, page } = usePostEditorContext();
    const { post, can } = page;
    const { state, view } = editor;
    const editable = can.update;

    return (
        <aside className="grid gap-5 lg:sticky lg:top-20">
            <ReadinessChecklist
                items={view.checklistItems}
                onFix={editable ? editor.applyFix : undefined}
                onJump={
                    editable
                        ? (key) => nav.jumpTo(checkSection[key] ?? 'compose')
                        : undefined
                }
                note={
                    editor.isDirty
                        ? 'Reflects your unsaved edits — save before scheduling.'
                        : undefined
                }
            />
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
