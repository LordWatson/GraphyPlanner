import { Sparkles } from 'lucide-react';
import { Panel } from '@/components/page';
import { AiCaptionSuggestions } from '@/components/post-editor/ai-caption-suggestions';
import { ChannelScopeBar, Composer } from '@/components/post-editor/composer';
import { usePostEditorContext } from '@/components/post-editor/editor/editor-context';
import { useAiCaptions } from '@/components/post-editor/editor/use-ai-captions';
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
