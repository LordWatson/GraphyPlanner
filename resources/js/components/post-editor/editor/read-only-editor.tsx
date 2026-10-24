import type { ImageStudioData } from '@/components/image-studio/types';
import type { MentionableUser } from '@/components/mention-textarea';
import { BusinessScheduleBar } from '@/components/post-editor/business-schedule-bar';
import { usePostEditorContext } from '@/components/post-editor/editor/editor-context';
import { EditorSidebar } from '@/components/post-editor/editor/editor-sidebar';
import { FeedbackSection } from '@/components/post-editor/editor/feedback-section';
import { ImageStudioSection } from '@/components/post-editor/editor/image-studio-section';
import { ReadOnlyContent } from '@/components/post-editor/editor/read-only-content';
import { PostDiscussion } from '@/components/post-editor/post-discussion';
import { PostWorkflow } from '@/components/post-editor/post-workflow';
import type {
    PostData,
    TransitionOption,
} from '@/components/post-editor/types';

export type ReadOnlyEditorCan = {
    comment: boolean;
    resend_review_email: boolean;
    retry_failed_targets: boolean;
    delete: boolean;
    override_approval: boolean;
    quick_schedule: boolean;
    share_preview: boolean;
};

/**
 * What viewers without edit rights (or anyone, while the post is locked) see in place of the
 * stepper: the lifecycle workflow above, then the saved post beside the preview rail, with the
 * feedback board, the discussion and the image studio folded into the left column so nothing is
 * orphaned full-width below. Mirrors the editable layout's placement (Step 24.11).
 */
export function ReadOnlyEditor({
    post,
    clientName,
    allowedTransitions,
    mentionableUsers,
    can,
    workflow,
    imageStudio,
    needsFeedback,
}: {
    post: PostData;
    clientName: string;
    allowedTransitions: TransitionOption[];
    mentionableUsers: MentionableUser[];
    can: ReadOnlyEditorCan;
    workflow: {
        is_business: boolean;
        requires_review: boolean;
        has_times: boolean;
    };
    imageStudio?: ImageStudioData | null;
    needsFeedback: boolean;
}) {
    const { editor } = usePostEditorContext();

    return (
        <>
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
                clientName={clientName}
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
                    <ReadOnlyContent post={post} />
                    <FeedbackSection post={post} canComment={can.comment} />
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
                    {imageStudio && <ImageStudioSection data={imageStudio} />}
                </div>
                <EditorSidebar />
            </div>
        </>
    );
}
