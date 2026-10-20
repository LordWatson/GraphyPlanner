import { Panel } from '@/components/page';
import { usePostEditorContext } from '@/components/post-editor/editor/editor-context';
import { MediaDetails } from '@/components/post-editor/media-details';
import { MediaPicker } from '@/components/post-editor/media-picker';

/** 03 Media — upload or pick from the library; order, alt text, cover and thumbnail. */
export function MediaSection() {
    const { editor, nav, page } = usePostEditorContext();
    const { state, view } = editor;

    return (
        <div ref={nav.sectionRef('media')} className="scroll-mt-24">
            <Panel
                eyebrow="03"
                title="Media"
                description="Upload something new or pick from the client's library — the number is the order it appears in."
                className={nav.flash('media')}
                actions={
                    <span className="text-muted-foreground font-mono text-xs">
                        {state.assetIds.length} attached
                    </span>
                }
            >
                <MediaPicker
                    clientId={page.client.id}
                    campaignId={
                        state.campaignId ? Number(state.campaignId) : undefined
                    }
                    selected={view.selectedAssets}
                    onChange={editor.setAttachedAssets}
                    canUpload={page.can.create_asset}
                    uploading={editor.uploading}
                    onUpload={(file) => void editor.upload(file)}
                />
                <MediaDetails
                    assets={view.selectedAssets}
                    altTexts={state.altTexts}
                    onAltTextChange={(id, text) =>
                        editor.update({
                            altTexts: { ...state.altTexts, [id]: text },
                        })
                    }
                    coverAssetId={state.coverAssetId}
                    onCoverChange={(coverAssetId) =>
                        editor.update({ coverAssetId })
                    }
                    thumbOffsetMs={state.thumbOffsetMs}
                    onThumbOffsetChange={(thumbOffsetMs) =>
                        editor.update({ thumbOffsetMs })
                    }
                    altTextPlatforms={view.altTextPlatforms}
                />
            </Panel>
        </div>
    );
}
