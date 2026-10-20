import type { ReactNode, Ref } from 'react';
import { ChannelComposer } from '@/components/post-editor/composer/channel-composer';
import type {
    ComposerScope,
    MasterContent,
} from '@/components/post-editor/composer/composer-parts';
import type { ComposerErrors } from '@/components/post-editor/composer/master-composer';
import { MasterComposer } from '@/components/post-editor/composer/master-composer';
import type {
    ChannelState,
    TargetAccountData,
} from '@/components/post-editor/types';

export { ChannelScopeBar } from '@/components/post-editor/composer/channel-scope-bar';
export type {
    ComposerScope,
    MasterContent,
} from '@/components/post-editor/composer/composer-parts';

/**
 * The post's writing surface. In "All channels" it edits the main caption, hashtags, link and
 * first comment (`MasterComposer`). With a channel picked, the same fields show what that channel
 * will publish (`ChannelComposer`). Link and first comment stay tucked behind chips until wanted.
 */
export function Composer({
    scope,
    accounts,
    master,
    onMasterChange,
    channels,
    onChannelChange,
    mediaKinds,
    hashtagSuggestions,
    errors,
    aiPanel,
    captionRef,
}: {
    scope: ComposerScope;
    accounts: TargetAccountData[];
    master: MasterContent;
    onMasterChange: (patch: Partial<MasterContent>) => void;
    channels: Record<number, ChannelState>;
    onChannelChange: (accountId: number, next: ChannelState) => void;
    mediaKinds: string[];
    hashtagSuggestions: string[];
    errors: ComposerErrors;
    /** AI suggestions, shown under the main caption only. */
    aiPanel?: ReactNode;
    captionRef?: Ref<HTMLTextAreaElement>;
}) {
    const account =
        scope === 'all' ? null : accounts.find((a) => a.id === scope);

    return account ? (
        <ChannelComposer
            key={account.id}
            account={account}
            master={master}
            channels={channels}
            onChannelChange={onChannelChange}
            mediaKinds={mediaKinds}
            hashtagSuggestions={hashtagSuggestions}
            captionRef={captionRef}
        />
    ) : (
        <MasterComposer
            accounts={accounts}
            master={master}
            onMasterChange={onMasterChange}
            channels={channels}
            hashtagSuggestions={hashtagSuggestions}
            errors={errors}
            aiPanel={aiPanel}
            captionRef={captionRef}
        />
    );
}
