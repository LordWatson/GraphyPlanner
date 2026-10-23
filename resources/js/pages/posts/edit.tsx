import type { FormComponentRef } from '@inertiajs/core';
import { Form, Head } from '@inertiajs/react';
import { useRef, useState } from 'react';
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
import { ImageStudioSection } from '@/components/post-editor/editor/image-studio-section';
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
    postAutosaves,
    useAutosave,
} from '@/components/post-editor/editor/use-autosave';
import { useEditorNavigation } from '@/components/post-editor/editor/use-editor-navigation';
import { usePostEditor } from '@/components/post-editor/editor/use-post-editor';
import { FeedbackBoard } from '@/components/feedback/feedback-board';
import { PostDiscussion } from '@/components/post-editor/post-discussion';
import { PostWorkflow } from '@/components/post-editor/post-workflow';
import { BusinessScheduleBar } from '@/components/post-editor/business-schedule-bar';
import type {
    PostData,
    TargetAccountData,
    TransitionOption,
} from '@/components/post-editor/types';
import { useKeyboardShortcut } from '@/hooks/use-keyboard-shortcut';
import { useUnsavedChangesGuard } from '@/hooks/use-unsaved-changes-guard';
import type { MediaFormatOptions } from '@/lib/media-format';
import { update as updatePost } from '@/routes/posts';
import {
    acceptSuggestion,
    dismissSuggestion,
    resolve as resolveFeedback,
} from '@/routes/comments';
import { store as storePostComment } from '@/routes/posts/comments';

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
    staff,
    workflow,
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
        duplicate: boolean;
        create_asset: boolean;
        quick_schedule: boolean;
        share_preview: boolean;
    };
    workflow: {
        is_business: boolean;
        requires_review: boolean;
        has_times: boolean;
    };
    hashtagSuggestions: string[];
    campaignOptions: CampaignOption[];
    mentionableUsers: MentionableUser[];
    ai?: { enabled: boolean; canUse: boolean };
    imageStudio?: ImageStudioData | null;
    mediaFormatOptions: MediaFormatOptions;
    /** Step M-5.4: internal staff for the assignee/reviewer pickers. */
    staff: { id: number; name: string }[];
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

    // Step M-5.3: autosave draft-like posts 5s after the last change (the hook no-ops when locked).
    useAutosave(can.update, post.status, editor.isDirty, editor.state, () =>
        formRef.current?.submit(),
    );

    // Open the discussion straight to the activity log when there's feedback to read.
    const needsFeedback = [
        'changes_requested',
        'failed',
        'partially_published',
    ].includes(post.status);

    const page = {
        post,
        client,
        targetAccounts,
        campaignOptions,
        hashtagSuggestions,
        mediaFormatOptions,
        staff,
        can,
    };

    return (
        <PostEditorProvider value={{ editor, nav, page }}>
            <Head title={`Edit post — ${client.name}`} />
            <Page>
                <EditorHeader post={post} client={client} grid={grid} />

                {workflow.is_business && (
                    <BusinessScheduleBar
                        postId={post.id}
                        status={post.status}
                        requiresReview={workflow.requires_review}
                        hasTimes={workflow.has_times}
                        canQuickSchedule={can.quick_schedule}
                        canSharePreview={can.share_preview}
                    />
                )}

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
                                            autosaves={postAutosaves(
                                                can.update,
                                                post.status,
                                            )}
                                            savedAt={post.updated_at}
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

                        {/* Step M-6.4: the visual-feedback board — pins, caption notes and suggested
                            edits the client left, which staff resolve (or accept) before approval. */}
                        {(post.feedback.length > 0 || can.comment) && (
                            <div className="border-border bg-card shadow-paper rounded-2xl border p-4">
                                <FeedbackBoard
                                    assets={post.assets}
                                    caption={post.master_caption ?? ''}
                                    threads={post.feedback}
                                    canComment={can.comment}
                                    mode="staff"
                                    routes={{
                                        create: storePostComment.url(post.id),
                                        resolve: (id) =>
                                            resolveFeedback.url(id),
                                        acceptSuggestion: (id) =>
                                            acceptSuggestion.url(id),
                                        dismissSuggestion: (id) =>
                                            dismissSuggestion.url(id),
                                    }}
                                />
                            </div>
                        )}
                    </div>

                    <EditorSidebar />
                </div>

                {imageStudio && <ImageStudioSection data={imageStudio} />}
            </Page>
            <ShortcutsDialog
                open={showShortcuts}
                onOpenChange={setShowShortcuts}
            />
        </PostEditorProvider>
    );
}
