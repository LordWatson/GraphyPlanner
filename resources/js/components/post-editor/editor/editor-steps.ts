import { checkSection } from '@/components/post-editor/editor/editor-state';
import type { EditorTab } from '@/components/post-editor/editor/use-editor-navigation';
import type { ChecklistItem } from '@/components/post-editor/types';

/** The four build panels, in the order a post is usually built. */
export const editorTabs: { id: EditorTab; label: string }[] = [
    { id: 'compose', label: 'Write' },
    { id: 'channels', label: 'Where & when' },
    { id: 'media', label: 'Media' },
    { id: 'settings', label: 'Settings' },
];

/** The final consolidation step: readiness + the one recommended move + every other move. */
export const reviewStep: { id: EditorTab; label: string } = {
    id: 'review',
    label: 'Review & send',
};

/** Every step shown on the spine: the four build panels, then Review & send. */
export const editorSteps = [...editorTabs, reviewStep];

export type TabStatus = 'error' | 'done' | 'todo' | 'attention' | 'neutral';

/** Which panel a server validation error belongs to, so a hidden error can't be missed. */
export function tabForErrorKey(key: string): EditorTab {
    if (key.startsWith('targets')) {
        return 'channels';
    }

    if (
        key.startsWith('asset') ||
        key.startsWith('media') ||
        key === 'cover_asset_id' ||
        key === 'thumb_offset_ms'
    ) {
        return 'media';
    }

    if (
        key === 'campaign_id' ||
        key === 'approval_mode' ||
        key === 'recurrence' ||
        key === 'recycle_after_days' ||
        key === 'is_recyclable' ||
        key.startsWith('location')
    ) {
        return 'settings';
    }

    // master_caption, hashtags, link_url, first_comment and anything unmapped.
    return 'compose';
}

/**
 * A step's readiness from the live checklist (grouped by `checkSection`): a blocking check still
 * open → "todo"; only a soft warning open → "attention"; all its checks pass → "done"; the panel
 * owns no checks (e.g. Settings with no campaign) → "neutral", no indicator.
 */
export function tabStatus(tab: EditorTab, items: ChecklistItem[]): TabStatus {
    const own = items.filter((item) => checkSection[item.key] === tab);

    if (own.length === 0) {
        return 'neutral';
    }

    if (own.some((item) => !item.soft && !item.passed)) {
        return 'todo';
    }

    if (own.some((item) => item.soft && !item.passed)) {
        return 'attention';
    }

    return 'done';
}

/**
 * The Review step's status summarises the whole post: every required check passing → "done",
 * otherwise "todo". (Server errors are layered on top by the spine.)
 */
export function reviewStatus(items: ChecklistItem[]): TabStatus {
    const required = items.filter((item) => !item.soft);

    if (required.length > 0 && required.every((item) => item.passed)) {
        return 'done';
    }

    return 'todo';
}
