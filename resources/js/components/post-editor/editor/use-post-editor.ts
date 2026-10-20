import { useState } from 'react';
import { toast } from 'sonner';
import type { CampaignOption } from '@/components/post-editor/campaign-field';
import type { MasterContent } from '@/components/post-editor/composer';
import { deriveEditorView } from '@/components/post-editor/editor/editor-derived';
import type { EditorState } from '@/components/post-editor/editor/editor-state';
import {
    initialEditorState,
    isEditorDirty,
} from '@/components/post-editor/editor/editor-state';
import type {
    AssetData,
    ChannelState,
    ChecklistFix,
    PostData,
    TargetAccountData,
} from '@/components/post-editor/types';
import { EMPTY_CHANNEL } from '@/components/post-editor/types';
import { http } from '@/lib/http';
import { usePlatforms } from '@/lib/platforms';
import { convertSlot } from '@/lib/timezones';
import { store as storeAsset } from '@/routes/clients/assets';

/**
 * Step I-4.6 / M-1.6 — the post editor's state and actions, shared through `PostEditorContext`.
 * One state object replaces the page's old seventeen `useState`s; the page remounts with fresh
 * props after every save/transition, so the initial snapshot is always "what's on the server".
 *
 * Destructive edits (removing a channel or media, discarding changes) toast with an Undo.
 */
export function usePostEditor({
    post,
    client,
    targetAccounts,
    campaignOptions,
    canUpdate,
}: {
    post: PostData;
    client: { id: number };
    targetAccounts: TargetAccountData[];
    campaignOptions: CampaignOption[];
    canUpdate: boolean;
}) {
    const [initial] = useState(() => initialEditorState(post, targetAccounts));
    const [state, setState] = useState<EditorState>(initial);
    // Details for every asset the editor has seen (saved, picked, uploaded); the library is paged.
    const [knownAssets, setKnownAssets] = useState<Record<number, AssetData>>(
        () => Object.fromEntries(post.assets.map((asset) => [asset.id, asset])),
    );
    const [uploading, setUploading] = useState(false);
    const registry = usePlatforms();

    const update = (patch: Partial<EditorState>) =>
        setState((current) => ({ ...current, ...patch }));

    const view = deriveEditorView({
        state,
        post,
        targetAccounts,
        knownAssets,
        campaignOptions,
        registry,
        canUpdate,
    });
    const isDirty = canUpdate && isEditorDirty(state, initial);

    const restore = (snapshot: EditorState) => () => setState(snapshot);
    const handleOf = (accountId: number) =>
        targetAccounts.find((a) => a.id === accountId)?.handle ?? 'channel';

    const discard = () => {
        const snapshot = state;
        setState(initial);
        toast('Changes discarded.', {
            action: { label: 'Undo', onClick: restore(snapshot) },
        });
    };

    const toggleChannel = (accountId: number) => {
        if (!(accountId in state.schedule)) {
            update({
                schedule: {
                    ...state.schedule,
                    [accountId]: { date: '', time: '' },
                },
            });

            return;
        }

        const snapshot = state;
        const schedule = { ...state.schedule };
        delete schedule[accountId];
        update({ schedule });
        toast(`Removed ${handleOf(accountId)}.`, {
            action: { label: 'Undo', onClick: restore(snapshot) },
        });
    };

    const updateSchedule = (
        accountId: number,
        field: 'date' | 'time',
        value: string,
    ) =>
        setState((current) => ({
            ...current,
            schedule: {
                ...current.schedule,
                [accountId]: { ...current.schedule[accountId], [field]: value },
            },
        }));

    // An explicit, user-triggered copy — each target still keeps (and is stored with) its own
    // local date/time, so this isn't the implicit "same instant" shortcut spec §4.6 forbids.
    const applyScheduleToAll = (sourceId: number) => {
        const source = state.schedule[sourceId];
        update({
            schedule: Object.fromEntries(
                Object.keys(state.schedule).map((id) => [id, { ...source }]),
            ),
        });
    };

    // Spec §4.6's explicit opt-in: every channel goes out at the source row's exact moment,
    // stored as that moment's local date/time on each channel's own clock.
    const applySameInstant = (sourceId: number) => {
        const zoneOf = (id: number) =>
            targetAccounts.find((a) => a.id === id)?.timezone ?? 'UTC';
        const source = state.schedule[sourceId];
        update({
            schedule: Object.fromEntries(
                Object.keys(state.schedule).map((id) => [
                    id,
                    convertSlot(source, zoneOf(sourceId), zoneOf(Number(id))) ??
                        state.schedule[Number(id)],
                ]),
            ),
        });
        toast.success('Every channel now goes out at the same moment.');
    };

    const setAttachedAssets = (attached: AssetData[]) => {
        setKnownAssets((current) => ({
            ...current,
            ...Object.fromEntries(attached.map((asset) => [asset.id, asset])),
        }));
        const removed = state.assetIds.filter(
            (id) => !attached.some((asset) => asset.id === id),
        ).length;
        const snapshot = state;
        update({ assetIds: attached.map((asset) => asset.id) });

        if (removed > 0) {
            toast(
                removed === 1 ? 'Media removed.' : `${removed} items removed.`,
                {
                    action: { label: 'Undo', onClick: restore(snapshot) },
                },
            );
        }
    };

    // Uploads straight to the client asset-store endpoint as JSON, so the new asset is merged in
    // place instead of redirecting away and losing unsaved editor changes.
    const upload = async (file: File) => {
        setUploading(true);
        const body = new FormData();
        body.append('source', 'upload');
        body.append('file', file);

        if (post.campaign_id) {
            body.append('campaign_id', String(post.campaign_id));
        }

        const result = await http.post<{ asset?: AssetData }>(
            storeAsset.url(client.id),
            body,
        );
        setUploading(false);

        if (!result.ok || !result.data.asset) {
            toast.error(
                result.ok ? 'Unable to upload the asset.' : result.message,
            );

            return;
        }

        const asset = result.data.asset;
        setKnownAssets((current) => ({ ...current, [asset.id]: asset }));
        setState((current) => ({
            ...current,
            assetIds: [...current.assetIds, asset.id],
        }));
        toast.success('Uploaded and added to this post.');
    };

    // Step M-2.3 / M-2.4: the checklist's one-click fixes change unsaved state only.
    const applyFix = (fix: ChecklistFix) => {
        if (fix.action === 'format' && fix.social_account_id) {
            const id = fix.social_account_id;
            setChannel(id, {
                ...(state.channels[id] ?? EMPTY_CHANNEL),
                format: fix.value,
            });
        }

        if (fix.action === 'media_format' && fix.platform) {
            update({
                mediaFormat: {
                    ...state.mediaFormat,
                    [fix.platform]: fix.value,
                },
            });
        }

        toast.success('Fixed — save to keep it.');
    };

    const setChannel = (accountId: number, next: ChannelState) =>
        setState((current) => ({
            ...current,
            channels: { ...current.channels, [accountId]: next },
        }));

    const master: MasterContent = {
        caption: state.caption,
        hashtags: state.hashtags,
        link: state.link,
        firstComment: state.firstComment,
    };

    return {
        initial,
        state,
        update,
        view,
        isDirty,
        uploading,
        master,
        discard,
        toggleChannel,
        updateSchedule,
        applyScheduleToAll,
        applySameInstant,
        setAttachedAssets,
        upload,
        applyFix,
        setChannel,
    };
}

export type PostEditor = ReturnType<typeof usePostEditor>;
