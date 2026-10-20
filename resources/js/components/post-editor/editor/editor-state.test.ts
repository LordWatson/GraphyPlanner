import { describe, expect, it } from 'vite-plus/test';
import {
    initialEditorState,
    isEditorDirty,
    mediaKindsOf,
    normalizeEditorState,
} from '@/components/post-editor/editor/editor-state';
import type {
    AssetData,
    PostData,
    TargetAccountData,
} from '@/components/post-editor/types';

const account = (id: number): TargetAccountData => ({
    id,
    platform: 'linkedin',
    platform_label: 'LinkedIn',
    handle: `account-${id}`,
    timezone: 'Europe/London',
    caption_limit: 3000,
    max_hashtags: 5,
    is_text_only_capable: true,
});

function post(overrides: Partial<PostData> = {}): PostData {
    return {
        campaign_id: null,
        master_caption: 'Hello',
        hashtags: ['coffee'],
        assets: [],
        targets: [
            {
                id: 10,
                social_account_id: 1,
                scheduled_local_date: '2026-10-23',
                scheduled_local_time: '09:30',
                caption_override: null,
                hashtags_override: null,
                first_comment: null,
                link_url: null,
                format: null,
            },
            {
                id: 11,
                social_account_id: 99, // disconnected: not in targetAccounts
                scheduled_local_date: '2026-10-23',
                scheduled_local_time: '10:00',
                caption_override: null,
                hashtags_override: null,
                first_comment: null,
                link_url: null,
                format: null,
            },
        ],
        music: null,
        location: null,
        media_format: {},
        link_url: null,
        first_comment: null,
        cover_asset_id: null,
        thumb_offset_ms: null,
        approval_mode: 'client_required',
        ...overrides,
    } as unknown as PostData;
}

describe('editor state', () => {
    it('starts from the saved post, leaving out disconnected channels', () => {
        const state = initialEditorState(post(), [account(1)]);

        expect(state.caption).toBe('Hello');
        expect(Object.keys(state.schedule)).toEqual(['1']);
        expect(state.schedule[1]).toEqual({
            date: '2026-10-23',
            time: '09:30',
        });
    });

    it('is clean until something changes, and clean again when changed back', () => {
        const initial = initialEditorState(post(), [account(1)]);

        expect(isEditorDirty(initial, initial)).toBe(false);
        expect(isEditorDirty({ ...initial, caption: 'Hello!' }, initial)).toBe(
            true,
        );
        expect(isEditorDirty({ ...initial, caption: 'Hello' }, initial)).toBe(
            false,
        );
    });

    it('drops edits for channels and media that are no longer attached', () => {
        const initial = initialEditorState(post(), [account(1)]);
        const edited = normalizeEditorState({
            ...initial,
            channels: {
                ...initial.channels,
                2: {
                    caption: 'Only on 2',
                    hashtags: null,
                    firstComment: null,
                    link: null,
                    format: null,
                },
            },
            altTexts: { 5: 'A latte' },
            crops: { 5: { x: 0.2, y: 0.5, zoom: 1.5 } },
        });

        expect(Object.keys(edited.channels)).toEqual(['1']);
        expect(edited.altTexts).toEqual({});
        expect(edited.crops).toEqual({});

        // ...so making then removing such an edit leaves the post clean.
        expect(
            isEditorDirty({ ...initial, altTexts: { 5: 'A latte' } }, initial),
        ).toBe(false);
    });

    it('sends just the video when a post has one', () => {
        const asset = (id: number, type: string) =>
            ({ id, type }) as unknown as AssetData;

        expect(
            mediaKindsOf([asset(1, 'image/png'), asset(2, 'image/jpeg')]),
        ).toEqual(['image', 'image']);
        expect(
            mediaKindsOf([asset(1, 'image/png'), asset(2, 'video/mp4')]),
        ).toEqual(['video']);
        expect(mediaKindsOf([])).toEqual([]);
    });
});
