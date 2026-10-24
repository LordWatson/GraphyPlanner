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
    ErrorStepSwitcher,
    StepActionBar,
    StepPanel,
    StepSpine,
} from '@/components/post-editor/editor/editor-stepper';
import { MediaSection } from '@/components/post-editor/editor/media-section';
import { ReadOnlyEditor } from '@/components/post-editor/editor/read-only-editor';
import { ReviewStep } from '@/components/post-editor/editor/review-step';
import { SettingsSection } from '@/components/post-editor/editor/settings-section';
import { ShortcutsDialog } from '@/components/post-editor/editor/shortcuts-dialog';
import {
    postAutosaves,
    useAutosave,
} from '@/components/post-editor/editor/use-autosave';
import { useEditorNavigation } from '@/components/post-editor/editor/use-editor-navigation';
import { usePostEditor } from '@/components/post-editor/editor/use-post-editor';
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
 * `PostEditorProvider`), and each panel is its own component. This page only composes them.
 *
 * Step 24.10 reshaped it into a guided stepper: a progress spine (`StepSpine`) over five steps —
 * Write, Where & when, Media, Settings, then Review & send — with one step shown at a time beside a
 * constant live preview (`EditorSidebar`), and a single action bar (`StepActionBar`) carrying the
 * save state and Back / Next. The lifecycle moves (quick schedule + the full `PostWorkflow`) now
 * live only inside the final Review & send step (`ReviewStep`), not stacked above the work. Read-only
 * viewers skip the stepper and see the saved post with the workflow above it.
 *
 * Step 24.11 folded the three modules that used to sit full-width at the bottom of the page into the
 * steps they belong to, so nothing is orphaned below the two-column grid: the AI image studio
 * ("Make it visual") lives in the Media step, and the feedback board and the discussion live in
 * Review & send (and, for read-only viewers, in the left column under the saved post). When there's
 * feedback waiting, the editor opens straight on Review & send.
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

    // When there's feedback to read (changes requested, a failed publish), open the editor on the
    // Review & send step — that's where the feedback board and the discussion now live — and open
    // the discussion straight to the activity log.
    const needsFeedback = [
        'changes_requested',
        'failed',
        'partially_published',
    ].includes(post.status);

    const nav = useEditorNavigation(
        Object.keys(editor.state.schedule).map(Number),
        can.update && needsFeedback ? 'review' : 'compose',
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

                {can.update ? (
                    <Form
                        ref={formRef}
                        {...updatePost.form(post.id)}
                        options={{
                            preserveScroll: true,
                            preserveState: 'errors',
                        }}
                        // `grid-cols-1` pins the single column to `minmax(0,1fr)` so no wide child
                        // (the step spine, a long caption/URL) can blow the form past the viewport
                        // on a phone. Extra bottom padding clears the docked action bar + tab bar.
                        className="grid grid-cols-1 gap-5 pb-16 md:pb-0"
                    >
                        {({ processing, errors }) => (
                            <>
                                <ErrorStepSwitcher errors={errors} />
                                <StepSpine errors={errors} />

                                <div className="grid min-w-0 grid-cols-1 gap-5 lg:grid-cols-[minmax(0,1fr)_360px] lg:items-start">
                                    <div className="grid min-w-0 grid-cols-1 gap-5">
                                        <StepPanel tab="compose">
                                            <ComposeSection
                                                errors={errors}
                                                showAi={Boolean(
                                                    ai?.enabled && ai?.canUse,
                                                )}
                                            />
                                        </StepPanel>
                                        <StepPanel tab="channels">
                                            <ChannelsSection
                                                error={
                                                    errors[
                                                        'targets.0.social_account_id'
                                                    ]
                                                }
                                            />
                                        </StepPanel>
                                        <StepPanel tab="media">
                                            <div className="grid gap-5">
                                                <MediaSection
                                                    showAi={Boolean(
                                                        ai?.enabled &&
                                                        ai?.canUse,
                                                    )}
                                                />
                                                {imageStudio && (
                                                    <ImageStudioSection
                                                        data={imageStudio}
                                                    />
                                                )}
                                            </div>
                                        </StepPanel>
                                        <StepPanel tab="settings">
                                            <SettingsSection errors={errors} />
                                        </StepPanel>
                                        <StepPanel tab="review">
                                            <ReviewStep
                                                post={post}
                                                clientName={client.name}
                                                allowedTransitions={
                                                    allowedTransitions
                                                }
                                                mentionableUsers={
                                                    mentionableUsers
                                                }
                                                can={can}
                                                workflow={workflow}
                                                needsFeedback={needsFeedback}
                                            />
                                        </StepPanel>
                                        <EditorFormFields />
                                    </div>

                                    <EditorSidebar />
                                </div>

                                <StepActionBar
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
                    <ReadOnlyEditor
                        post={post}
                        clientName={client.name}
                        allowedTransitions={allowedTransitions}
                        mentionableUsers={mentionableUsers}
                        can={can}
                        workflow={workflow}
                        imageStudio={imageStudio}
                        needsFeedback={needsFeedback}
                    />
                )}
            </Page>
            <ShortcutsDialog
                open={showShortcuts}
                onOpenChange={setShowShortcuts}
            />
        </PostEditorProvider>
    );
}
