import { useState } from 'react';
import { toast } from 'sonner';
import { Panel } from '@/components/page';
import { usePostEditorContext } from '@/components/post-editor/editor/editor-context';
import { MediaDetails } from '@/components/post-editor/media-details';
import { MediaPicker } from '@/components/post-editor/media-picker';
import { http } from '@/lib/http';
import aiPosts from '@/routes/posts/ai';

/** 03 Media — upload or pick from the library; order, alt text, cover and thumbnail. */
export function MediaSection({ showAi = false }: { showAi?: boolean }) {
    const { editor, nav, page } = usePostEditorContext();
    const { state, view } = editor;
    const [suggestingAltTextFor, setSuggestingAltTextFor] = useState<
        number | null
    >(null);

    // Step M-7.3: multimodal "generate alt text from the image". Fills the field for the user to
    // confirm; saved like any other edit.
    const suggestAltText = async (assetId: number) => {
        setSuggestingAltTextFor(assetId);
        const result = await http.post<{ altText: string | null }>(
            aiPosts.altText.url({ post: page.post.id, asset: assetId }),
            undefined,
            {
                toastOnError: true,
                rateLimitMessage:
                    'That’s a lot of AI in a short time. Give it a minute.',
            },
        );
        setSuggestingAltTextFor(null);

        if (!result.ok) {
            return;
        }

        if (!result.data.altText) {
            toast('Couldn’t read that image — write the alt text yourself.');

            return;
        }

        editor.update({
            altTexts: { ...state.altTexts, [assetId]: result.data.altText },
        });
    };

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
                    onSuggestAltText={
                        showAi ? (id) => void suggestAltText(id) : undefined
                    }
                    suggestingAltTextFor={suggestingAltTextFor}
                />
            </Panel>
        </div>
    );
}
