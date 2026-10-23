import { createContext, useContext } from 'react';
import type { CampaignOption } from '@/components/post-editor/campaign-field';
import type { EditorNavigation } from '@/components/post-editor/editor/use-editor-navigation';
import type { PostEditor } from '@/components/post-editor/editor/use-post-editor';
import type {
    PostData,
    TargetAccountData,
} from '@/components/post-editor/types';
import type { MediaFormatOptions } from '@/lib/media-format';

export type PostEditorPageData = {
    post: PostData;
    client: { id: number; name: string };
    targetAccounts: TargetAccountData[];
    campaignOptions: CampaignOption[];
    hashtagSuggestions: string[];
    mediaFormatOptions: MediaFormatOptions;
    /** Step M-5.4: internal staff for the assignee/reviewer pickers. */
    staff: { id: number; name: string }[];
    can: { update: boolean; create_asset: boolean; duplicate: boolean };
};

type PostEditorContextValue = {
    editor: PostEditor;
    nav: EditorNavigation;
    page: PostEditorPageData;
};

const PostEditorContext = createContext<PostEditorContextValue | null>(null);

export const PostEditorProvider = PostEditorContext.Provider;

/** Step I-4.6 / M-1.6 — the editor sections read state, actions and page props from here. */
export function usePostEditorContext(): PostEditorContextValue {
    const value = useContext(PostEditorContext);

    if (!value) {
        throw new Error('usePostEditorContext needs a <PostEditorProvider>.');
    }

    return value;
}
