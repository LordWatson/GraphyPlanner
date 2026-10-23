import { usePostEditorContext } from '@/components/post-editor/editor/editor-context';

/**
 * The fields the editor's controls don't submit themselves (the composer, preview rail and
 * settings are controlled, unnamed inputs), in one place so the request shape is easy to read.
 * Channel targets are sent by `TargetFields`, media ids by `MediaPicker`, the sound by
 * `MusicField` and the location by its own input.
 */
export function EditorFormFields() {
    const { editor } = usePostEditorContext();
    const { state, view } = editor;

    return (
        <>
            <input type="hidden" name="master_caption" value={state.caption} />
            {state.hashtags.map((tag, i) => (
                <input
                    key={i}
                    type="hidden"
                    name={`hashtags[${i}]`}
                    value={tag}
                />
            ))}
            <input type="hidden" name="link_url" value={state.link} />
            <input
                type="hidden"
                name="first_comment"
                value={state.firstComment}
            />
            <input type="hidden" name="campaign_id" value={state.campaignId} />
            <input
                type="hidden"
                name="approval_mode"
                value={state.approvalMode}
            />
            {/* Step M-5.4: assignment and the internal due date. */}
            <input type="hidden" name="assignee_id" value={state.assigneeId} />
            <input type="hidden" name="reviewer_id" value={state.reviewerId} />
            <input type="hidden" name="due_at" value={state.dueDate} />
            {/* Step M-5.8: evergreen reuse and repeat. */}
            <input
                type="hidden"
                name="is_recyclable"
                value={state.isRecyclable ? '1' : '0'}
            />
            <input
                type="hidden"
                name="recycle_after_days"
                value={state.isRecyclable ? state.recycleAfterDays : ''}
            />
            <input type="hidden" name="recurrence" value={state.recurrence} />
            {/* Image format + framing are set from the preview rail. */}
            {Object.entries(state.mediaFormat).map(([platform, ratio]) => (
                <input
                    key={platform}
                    type="hidden"
                    name={`media_format[${platform}]`}
                    value={ratio ?? ''}
                />
            ))}
            {Object.entries(view.saved.crops).map(([id, crop]) =>
                (['x', 'y', 'zoom'] as const).map((field) => (
                    <input
                        key={`${id}-${field}`}
                        type="hidden"
                        name={`asset_crops[${id}][${field}]`}
                        value={String(crop[field])}
                    />
                )),
            )}
            {state.assetIds.map((id) => (
                <input
                    key={id}
                    type="hidden"
                    name={`asset_alt_texts[${id}]`}
                    value={state.altTexts[id] ?? ''}
                />
            ))}
            <input
                type="hidden"
                name="cover_asset_id"
                value={state.coverAssetId ?? ''}
            />
            <input
                type="hidden"
                name="thumb_offset_ms"
                value={state.thumbOffsetMs ?? ''}
            />
        </>
    );
}
