import { Shuffle, Sparkles } from 'lucide-react';
import { toast } from 'sonner';
import { Panel } from '@/components/page';
import { AiCaptionSuggestions } from '@/components/post-editor/ai-caption-suggestions';
import { ChannelScopeBar, Composer } from '@/components/post-editor/composer';
import { usePostEditorContext } from '@/components/post-editor/editor/editor-context';
import { useAiCaptions } from '@/components/post-editor/editor/use-ai-captions';
import { useAiRepurpose } from '@/components/post-editor/editor/use-ai-repurpose';
import { WritingToolsMenu } from '@/components/post-editor/editor/writing-tools-menu';
import { isCustomized } from '@/components/post-editor/types';
import { Button } from '@/components/ui/button';

/** 01 Write — the caption, hashtags, link and first comment, for all channels or one. */
export function ComposeSection({
    errors,
    showAi,
}: {
    errors: Record<string, string>;
    showAi: boolean;
}) {
    const { editor, nav, page } = usePostEditorContext();
    const { view } = editor;
    const ai = useAiCaptions({
        postId: page.post.id,
        caption: editor.state.caption,
        onApply: (caption, hashtags) => editor.update({ caption, hashtags }),
    });

    const platforms = new Set(
        view.selectedAccounts.map((account) => account.platform),
    );
    const repurpose = useAiRepurpose({
        postId: page.post.id,
        accounts: view.selectedAccounts,
        channels: editor.state.channels,
        setChannel: editor.setChannel,
        anyCustomized: view.selectedAccounts.some((account) =>
            isCustomized(editor.state.channels[account.id]),
        ),
    });

    const runRepurpose = async () => {
        const applied = await repurpose.repurpose();

        if (typeof applied === 'number') {
            toast.success(
                applied > 0
                    ? 'Repurposed for each platform — save to keep it.'
                    : 'Nothing to repurpose this time.',
            );
        }
    };

    return (
        <div ref={nav.sectionRef('compose')} className="scroll-mt-24">
            <Panel
                eyebrow="01"
                title="Write"
                className={nav.flash('compose')}
                bodyClassName="grid gap-5"
                actions={
                    showAi &&
                    nav.activeScope === 'all' && (
                        <div className="flex gap-2">
                            <WritingToolsMenu
                                postId={page.post.id}
                                caption={editor.state.caption}
                                onRewrite={(next) =>
                                    editor.update({ caption: next })
                                }
                            />
                            {platforms.size >= 2 && (
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    onClick={() => void runRepurpose()}
                                    disabled={repurpose.loading}
                                >
                                    <Shuffle />
                                    {repurpose.loading
                                        ? 'Repurposing…'
                                        : 'Repurpose per platform'}
                                </Button>
                            )}
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                onClick={() => void ai.fetchSuggestions()}
                                disabled={ai.loading}
                            >
                                <Sparkles />
                                {ai.loading ? 'Thinking…' : 'Suggest with AI'}
                            </Button>
                            {ai.loading && (
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="ghost"
                                    onClick={() => ai.cancel()}
                                >
                                    Cancel
                                </Button>
                            )}
                        </div>
                    )
                }
            >
                <ChannelScopeBar
                    scope={nav.activeScope}
                    onScopeChange={nav.changeScope}
                    accounts={view.selectedAccounts}
                    channels={view.saved.channels}
                    master={editor.master}
                    onAddChannels={() => nav.jumpTo('channels')}
                />
                <Composer
                    scope={nav.activeScope}
                    accounts={view.selectedAccounts}
                    master={editor.master}
                    onMasterChange={editor.update}
                    channels={view.saved.channels}
                    onChannelChange={editor.setChannel}
                    mediaKinds={view.mediaKinds}
                    hashtagSuggestions={page.hashtagSuggestions}
                    errors={errors}
                    captionRef={nav.captionRef}
                    aiPanel={
                        <>
                            {ai.error && (
                                <p className="text-destructive text-xs">
                                    {ai.error}
                                </p>
                            )}
                            <AiCaptionSuggestions
                                suggestions={ai.suggestions}
                                policyWarnings={ai.policyWarnings}
                                brandFit={ai.brandFit}
                                appliedIndex={ai.appliedIndex}
                                expanded={ai.expanded}
                                onToggleExpanded={ai.toggleExpanded}
                                onApply={ai.apply}
                            />
                        </>
                    }
                />
            </Panel>
        </div>
    );
}
