import type { FormComponentRef } from '@inertiajs/core';
import { Form, Head } from '@inertiajs/react';
import { ChevronDown, Sparkles } from 'lucide-react';
import { useRef, useState } from 'react';
import { ImageStudio } from '@/components/image-studio/image-studio';
import type { ImageStudioData } from '@/components/image-studio/types';
import type { MentionableUser } from '@/components/mention-textarea';
import { Page } from '@/components/page';
import type { CampaignOption } from '@/components/post-editor/campaign-field';
import { ChannelsSection } from '@/components/post-editor/editor/channels-section';
import { ComposeSection } from '@/components/post-editor/editor/compose-section';
import { PostEditorProvider } from '@/components/post-editor/editor/editor-context';
import { EditorFormFields } from '@/components/post-editor/editor/editor-form-fields';
import type { EditorGridLink } from '@/components/post-editor/editor/editor-header';
import { EditorHeader } from '@/components/post-editor/editor/editor-header';
import { EditorSidebar } from '@/components/post-editor/editor/editor-sidebar';
import {
    EditorTabs,
    ErrorTabSwitcher,
    TabFooter,
    TabPanel,
} from '@/components/post-editor/editor/editor-tabs';
import { MediaSection } from '@/components/post-editor/editor/media-section';
import { ReadOnlyContent } from '@/components/post-editor/editor/read-only-content';
import { SaveBar } from '@/components/post-editor/editor/save-bar';
import { SettingsSection } from '@/components/post-editor/editor/settings-section';
import { ShortcutsDialog } from '@/components/post-editor/editor/shortcuts-dialog';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { useEditorNavigation } from '@/components/post-editor/editor/use-editor-navigation';
import { usePostEditor } from '@/components/post-editor/editor/use-post-editor';
import { PostDiscussion } from '@/components/post-editor/post-discussion';
import { PostWorkflow } from '@/components/post-editor/post-workflow';
import type {
    PostData,
    TargetAccountData,
    TransitionOption,
} from '@/components/post-editor/types';
import { useKeyboardShortcut } from '@/hooks/use-keyboard-shortcut';
import { useUnsavedChangesGuard } from '@/hooks/use-unsaved-changes-guard';
import type { MediaFormatOptions } from '@/lib/media-format';
import { update as updatePost } from '@/routes/posts';

/**
 * The post editor. Step I-4.6 / M-1.6 split it into feature components under
 * `components/post-editor/editor/`: state and actions live in `usePostEditor` (shared through
 * `PostEditorProvider`), and each panel — write, where & when, media, settings, the right rail —
 * is its own component. This page only composes them.
 *
 * Shortcuts: ⌘/Ctrl+S saves, ⌘/Ctrl+Enter opens "send for review" (in `PostWorkflow`), ⌘/Ctrl+K
 * opens the command palette, `?` lists them.
 */
export default function PostEdit({
    post,
    client,
    targetAccounts,
    allowedTransitions,
    can,
    hashtagSuggestions,
    campaignOptions,
    mentionableUsers,
    ai,
    imageStudio,
    mediaFormatOptions,
    grid = null,
}: {
    post: PostData;
    client: { id: number; name: string };
    targetAccounts: TargetAccountData[];
    allowedTransitions: TransitionOption[];
    can: {
        update: boolean;
        comment: boolean;
        resend_review_email: boolean;
        retry_failed_targets: boolean;
        delete: boolean;
        override_approval: boolean;
        create_asset: boolean;
    };
    hashtagSuggestions: string[];
    campaignOptions: CampaignOption[];
    mentionableUsers: MentionableUser[];
    ai?: { enabled: boolean; canUse: boolean };
    imageStudio?: ImageStudioData | null;
    mediaFormatOptions: MediaFormatOptions;
    /** Step 18.9: the grid tile this post was made from. */
    grid?: EditorGridLink | null;
}) {
    const editor = usePostEditor({
        post,
        client,
        targetAccounts,
        campaignOptions,
        canUpdate: can.update,
    });
    const nav = useEditorNavigation(
        Object.keys(editor.state.schedule).map(Number),
    );
    const formRef = useRef<FormComponentRef>(null);
    const [showShortcuts, setShowShortcuts] = useState(false);

    useUnsavedChangesGuard(editor.isDirty);
    useKeyboardShortcut({ key: 's', mod: true, whileTyping: true }, () => {
        if (editor.isDirty) {
            formRef.current?.submit();
        }
    });
    useKeyboardShortcut({ key: '?' }, () => setShowShortcuts(true));

    // Open the discussion straight to the activity log when there's feedback to read.
    const needsFeedback = [
        'changes_requested',
        'failed',
        'partially_published',
    ].includes(post.status);

    // How many images the studio already holds for this post, shown on its collapsed card.
    const imageCount = (imageStudio?.generations ?? []).reduce(
        (sum, generation) => sum + generation.variants.length,
        0,
    );

    const page = {
        post,
        client,
        targetAccounts,
        campaignOptions,
        hashtagSuggestions,
        mediaFormatOptions,
        can,
    };

    return (
        <PostEditorProvider value={{ editor, nav, page }}>
            <Head title={`Edit post — ${client.name}`} />
            <Page>
                <EditorHeader post={post} client={client} grid={grid} />

                <PostWorkflow
                    post={post}
                    clientName={client.name}
                    allowedTransitions={allowedTransitions}
                    isDirty={editor.isDirty}
                    mentionableUsers={mentionableUsers}
                    canResendReviewEmail={can.resend_review_email}
                    canRetryFailedTargets={can.retry_failed_targets}
                    canDelete={can.delete}
                    canOverrideApproval={can.override_approval}
                />

                <div className="grid grid-cols-1 gap-5 lg:grid-cols-[minmax(0,1fr)_360px] lg:items-start">
                    <div className="grid min-w-0 gap-5">
                        {can.update ? (
                            <Form
                                ref={formRef}
                                {...updatePost.form(post.id)}
                                options={{
                                    preserveScroll: true,
                                    preserveState: 'errors',
                                }}
                                className="grid gap-5"
                            >
                                {({ processing, errors }) => (
                                    <>
                                        <ErrorTabSwitcher errors={errors} />
                                        <EditorTabs errors={errors} />
                                        <TabPanel tab="compose">
                                            <ComposeSection
                                                errors={errors}
                                                showAi={Boolean(
                                                    ai?.enabled && ai?.canUse,
                                                )}
                                            />
                                        </TabPanel>
                                        <TabPanel tab="channels">
                                            <ChannelsSection
                                                error={
                                                    errors[
                                                        'targets.0.social_account_id'
                                                    ]
                                                }
                                            />
                                        </TabPanel>
                                        <TabPanel tab="media">
                                            <MediaSection />
                                        </TabPanel>
                                        <TabPanel tab="settings">
                                            <SettingsSection errors={errors} />
                                        </TabPanel>
                                        <TabFooter />
                                        <EditorFormFields />
                                        <SaveBar
                                            isDirty={editor.isDirty}
                                            processing={processing}
                                            onDiscard={editor.discard}
                                            onShowShortcuts={() =>
                                                setShowShortcuts(true)
                                            }
                                        />
                                    </>
                                )}
                            </Form>
                        ) : (
                            <ReadOnlyContent post={post} />
                        )}

                        <PostDiscussion
                            postId={post.id}
                            comments={post.comments}
                            activityLogs={post.activity_logs}
                            canComment={can.comment}
                            canResendReviewEmail={can.resend_review_email}
                            mentionableUsers={mentionableUsers}
                            collapsible
                            defaultOpen={needsFeedback}
                            defaultTab={needsFeedback ? 'activity' : 'comments'}
                        />
                    </div>

                    <EditorSidebar />
                </div>

                {imageStudio && (
                    <Collapsible
                        id="image-studio"
                        className="border-border bg-card scroll-mt-20 rounded-2xl border shadow-sm"
                    >
                        <CollapsibleTrigger className="group hover:bg-muted/40 focus-visible:ring-ring/50 flex w-full items-center gap-4 rounded-2xl p-5 text-start transition-colors outline-none focus-visible:ring-[3px]">
                            <span className="bg-accent text-primary flex size-11 shrink-0 items-center justify-center rounded-full">
                                <Sparkles className="size-5" />
                            </span>
                            <span className="grid min-w-0 flex-1 gap-0.5">
                                <span className="flex flex-wrap items-center gap-x-2 gap-y-0.5">
                                    <span className="font-display text-lg leading-tight">
                                        Make it{' '}
                                        <em className="text-primary not-italic">
                                            visual
                                        </em>
                                        .
                                    </span>
                                    {imageCount > 0 && (
                                        <span className="eyebrow text-muted-foreground">
                                            {imageCount} image
                                            {imageCount === 1 ? '' : 's'}
                                        </span>
                                    )}
                                </span>
                                <span className="text-muted-foreground text-[13px] text-pretty">
                                    Generate on-brand images for this post with
                                    AI — art-directed from the client's brand.
                                </span>
                            </span>
                            <span className="border-border text-muted-foreground group-hover:border-primary/40 group-hover:text-primary flex size-9 shrink-0 items-center justify-center rounded-full border transition-colors">
                                <ChevronDown className="size-4 transition-transform group-data-[state=open]:rotate-180" />
                                <span className="sr-only">
                                    Open the image studio
                                </span>
                            </span>
                        </CollapsibleTrigger>
                        <CollapsibleContent className="border-border/70 border-t p-5 pt-6">
                            <ImageStudio data={imageStudio} hideHeader />
                        </CollapsibleContent>
                    </Collapsible>
                )}
            </Page>
            <ShortcutsDialog
                open={showShortcuts}
                onOpenChange={setShowShortcuts}
            />
        </PostEditorProvider>
    );
}
