import type { MentionableUser } from '@/components/mention-textarea';
import { BusinessScheduleBar } from '@/components/post-editor/business-schedule-bar';
import { usePostEditorContext } from '@/components/post-editor/editor/editor-context';
import { checkSection } from '@/components/post-editor/editor/editor-state';
import { PostWorkflow } from '@/components/post-editor/post-workflow';
import { ReadinessChecklist } from '@/components/post-editor/readiness-checklist';
import type {
    PostData,
    TransitionOption,
} from '@/components/post-editor/types';

export type ReviewStepCan = {
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
 * 05 Review & send — the final step, and the only place the post's lifecycle is driven. It gathers
 * what used to sit above the editor: the readiness checklist (what's left before it can go out),
 * the business one-tap schedule bar, and the full `PostWorkflow` (stage track, the one recommended
 * move, and every other legal move). Nothing here is new; it is simply where "send it" now lives.
 */
export function ReviewStep({
    post,
    clientName,
    allowedTransitions,
    mentionableUsers,
    can,
    workflow,
}: {
    post: PostData;
    clientName: string;
    allowedTransitions: TransitionOption[];
    mentionableUsers: MentionableUser[];
    can: ReviewStepCan;
    workflow: ReviewStepWorkflow;
}) {
    const { editor, nav } = usePostEditorContext();

    return (
        <div className="grid gap-5">
            <div className="flex flex-col gap-1">
                <span className="eyebrow text-muted-foreground">05</span>
                <h2 className="text-[17px] leading-snug font-semibold tracking-[-0.01em]">
                    Review &amp; send
                </h2>
                <p className="text-muted-foreground text-[13px]">
                    A last look, then move it along.
                </p>
            </div>

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
        </div>
    );
}
