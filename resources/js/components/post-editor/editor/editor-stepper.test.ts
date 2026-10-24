import { describe, expect, it } from 'vite-plus/test';
import {
    editorSteps,
    reviewStatus,
    tabForErrorKey,
    tabStatus,
} from '@/components/post-editor/editor/editor-steps';
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

describe('reviewStatus', () => {
    it('is done when every required check passes', () => {
        const items = [item('caption', true), item('targets', true)];
        expect(reviewStatus(items)).toBe('done');
    });

    it('is todo while a required check is open (soft checks do not count)', () => {
        const items = [
            item('caption', false),
            item('campaign_hashtags', false, true),
        ];
        expect(reviewStatus(items)).toBe('todo');
    });

    it('is todo when there are no required checks yet', () => {
        expect(reviewStatus([])).toBe('todo');
    });
});

describe('editorSteps', () => {
    it('ends with the Review & send step', () => {
        const ids = editorSteps.map((step) => step.id);
        expect(ids).toEqual([
            'compose',
            'channels',
            'media',
            'settings',
            'review',
        ]);
    });
});

describe('tabForErrorKey', () => {
    it('routes each error key to its step', () => {
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
