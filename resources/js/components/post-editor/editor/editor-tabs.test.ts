import { describe, expect, it } from 'vite-plus/test';
import {
    tabForErrorKey,
    tabStatus,
} from '@/components/post-editor/editor/editor-tabs';
import type { ChecklistItem } from '@/components/post-editor/types';

const item = (key: string, passed: boolean, soft = false): ChecklistItem => ({
    key,
    label: key,
    passed,
    soft,
});

describe('tabStatus', () => {
    it('is neutral when the panel owns no checks', () => {
        expect(tabStatus('settings', [item('caption', false)])).toBe('neutral');
    });

    it('is todo while a blocking check is open', () => {
        const items = [item('caption', false), item('hashtags', true)];
        expect(tabStatus('compose', items)).toBe('todo');
    });

    it('is attention when only a soft check is open', () => {
        const items = [item('campaign_hashtags', false, true)];
        expect(tabStatus('settings', items)).toBe('attention');
    });

    it('is done when every owned check passes', () => {
        const items = [item('caption', true), item('hashtags', true)];
        expect(tabStatus('compose', items)).toBe('done');
    });

    it('ignores media framing, which belongs to the preview rail', () => {
        expect(tabStatus('media', [item('media_framing', false)])).toBe(
            'neutral',
        );
    });
});

describe('tabForErrorKey', () => {
    it('routes each error key to its panel', () => {
        expect(tabForErrorKey('master_caption')).toBe('compose');
        expect(tabForErrorKey('hashtags.0')).toBe('compose');
        expect(tabForErrorKey('targets.0.social_account_id')).toBe('channels');
        expect(tabForErrorKey('asset_alt_texts.5')).toBe('media');
        expect(tabForErrorKey('media_format.instagram')).toBe('media');
        expect(tabForErrorKey('cover_asset_id')).toBe('media');
        expect(tabForErrorKey('campaign_id')).toBe('settings');
        expect(tabForErrorKey('approval_mode')).toBe('settings');
        expect(tabForErrorKey('location.name')).toBe('settings');
    });
});
