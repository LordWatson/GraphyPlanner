import type { MentionableUser } from '@/components/mention-textarea';
import { BusinessScheduleBar } from '@/components/post-editor/business-schedule-bar';
import { usePostEditorContext } from '@/components/post-editor/editor/editor-context';
import { checkSection } from '@/components/post-editor/editor/editor-state';
import { FeedbackSection } from '@/components/post-editor/editor/feedback-section';
import { PostDiscussion } from '@/components/post-editor/post-discussion';
import { PostWorkflow } from '@/components/post-editor/post-workflow';
import { ReadinessChecklist } from '@/components/post-editor/readiness-checklist';
import type {
    PostData,
    TransitionOption,
} from '@/components/post-editor/types';

export type ReviewStepCan = {
    comment: boolean;
    resend_review_email: boolean;
    retry_failed_targets: boolean;
    delete: boolean;
    override_approval: boolean;
    quick_schedule: boolean;
    share_preview: boolean;
};

export type ReviewStepWorkflow = {
    is_business: boolean;
    requires_review: boolean;
    has_times: boolean;
};

/**
 * 05 Review & send — the final step, and the only place the post's lifecycle is driven. It reads
 * top-down as "a last look, then move it along": the readiness checklist (what's left), the send
 * actions (business one-tap schedule + the full `PostWorkflow`), the client feedback to clear, then
 * the discussion (comments + activity log).
 *
 * Step 24.11 pulled the feedback board and the discussion in here, off the bottom of the page, so
 * every collaboration surface lives in the one step where you decide to send — not stacked
 * full-width below the editor. When there's feedback waiting (`needsFeedback`), the editor opens on
 * this step and the discussion opens straight to the activity log.
 */
export function ReviewStep({
    post,
    clientName,
    allowedTransitions,
    mentionableUsers,
    can,
    workflow,
    needsFeedback,
}: {
    post: PostData;
    clientName: string;
    allowedTransitions: TransitionOption[];
    mentionableUsers: MentionableUser[];
    can: ReviewStepCan;
    workflow: ReviewStepWorkflow;
    /** Status needs attention (changes requested / failed) — opens the discussion on activity. */
    needsFeedback: boolean;
}) {
    const { editor, nav } = usePostEditorContext();

    return (
        // `grid-cols-1` pins the column to `minmax(0,1fr)` so wide children (the workflow's stage
        // track) get a bounded width and scroll inside it, instead of growing the column and
        // pushing the whole step off a phone screen.
        <div className="grid min-w-0 grid-cols-1 gap-5">
            <div className="flex flex-col gap-1">
                <span className="eyebrow text-muted-foreground">05</span>
                <h2 className="text-[17px] leading-snug font-semibold tracking-[-0.01em]">
                    Review &amp; send
                </h2>
                <p className="text-muted-foreground text-[13px]">
                    A last look, then move it along.
                </p>
            </div>

            <ReadinessChecklist
                items={editor.view.checklistItems}
                onFix={editor.applyFix}
                onJump={(key) => nav.jumpTo(checkSection[key] ?? 'compose')}
                note={
                    editor.isDirty
                        ? 'Reflects your unsaved edits — save before scheduling.'
                        : undefined
                }
            />

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
        </div>
    );
}
