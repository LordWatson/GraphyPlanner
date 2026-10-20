import type { FormComponentRef } from '@inertiajs/core';
import { Form, Head } from '@inertiajs/react';
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
import { MediaSection } from '@/components/post-editor/editor/media-section';
import { ReadOnlyContent } from '@/components/post-editor/editor/read-only-content';
import { SaveBar } from '@/components/post-editor/editor/save-bar';
import { SettingsSection } from '@/components/post-editor/editor/settings-section';
import { ShortcutsDialog } from '@/components/post-editor/editor/shortcuts-dialog';
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
                                        <ComposeSection
                                            errors={errors}
                                            showAi={Boolean(
                                                ai?.enabled && ai?.canUse,
                                            )}
                                        />
                                        <ChannelsSection
                                            error={
                                                errors[
                                                    'targets.0.social_account_id'
                                                ]
                                            }
                                        />
                                        <MediaSection />
                                        <SettingsSection errors={errors} />
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
                        />
                    </div>

                    <EditorSidebar />
                </div>

                {imageStudio && (
                    <div
                        id="image-studio"
                        className="border-foreground scroll-mt-20 border-t pt-8"
                    >
                        <ImageStudio data={imageStudio} />
                    </div>
                )}
            </Page>
            <ShortcutsDialog
                open={showShortcuts}
                onOpenChange={setShowShortcuts}
            />
        </PostEditorProvider>
    );
}
