import { CalendarClock, UserRound } from 'lucide-react';
import { DatePicker } from '@/components/date-picker';
import InputError from '@/components/input-error';
import { Panel } from '@/components/page';
import { usePostEditorContext } from '@/components/post-editor/editor/editor-context';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

const UNASSIGNED = '__none';

/** 05 Assignment — who owns this post, who reviews it, and when it's due. */
export function AssignmentPanel({
    errors,
}: {
    errors: Record<string, string>;
}) {
    const { editor, page } = usePostEditorContext();
    const { state } = editor;

    return (
        <Panel
            eyebrow="05"
            title="Assignment"
            description="Who owns this post, who reviews it, and when it's due."
            className="mt-5"
            bodyClassName="grid gap-5 sm:grid-cols-3"
        >
            <div className="grid content-start gap-2">
                <Label
                    htmlFor="assignee_id"
                    className="flex items-center gap-1.5"
                >
                    <UserRound className="text-muted-foreground size-3.5" />
                    Assignee
                </Label>
                <StaffSelect
                    id="assignee_id"
                    value={state.assigneeId}
                    staff={page.staff}
                    onChange={(assigneeId) => editor.update({ assigneeId })}
                />
                <InputError message={errors.assignee_id} />
            </div>
            <div className="grid content-start gap-2">
                <Label
                    htmlFor="reviewer_id"
                    className="flex items-center gap-1.5"
                >
                    <UserRound className="text-muted-foreground size-3.5" />
                    Reviewer
                </Label>
                <StaffSelect
                    id="reviewer_id"
                    value={state.reviewerId}
                    staff={page.staff}
                    onChange={(reviewerId) => editor.update({ reviewerId })}
                />
                <InputError message={errors.reviewer_id} />
            </div>
            <div className="grid content-start gap-2">
                <Label htmlFor="due_at" className="flex items-center gap-1.5">
                    <CalendarClock className="text-muted-foreground size-3.5" />
                    Due date
                </Label>
                <DatePicker
                    id="due_at"
                    value={state.dueDate}
                    onChange={(value) =>
                        editor.update({ dueDate: value ?? '' })
                    }
                />
                <InputError message={errors.due_at} />
            </div>
        </Panel>
    );
}

function StaffSelect({
    id,
    value,
    staff,
    onChange,
}: {
    id: string;
    value: string;
    staff: { id: number; name: string }[];
    onChange: (value: string) => void;
}) {
    return (
        <Select
            value={value || UNASSIGNED}
            onValueChange={(next) => onChange(next === UNASSIGNED ? '' : next)}
        >
            <SelectTrigger id={id}>
                <SelectValue placeholder="Unassigned" />
            </SelectTrigger>
            <SelectContent>
                <SelectItem value={UNASSIGNED}>Unassigned</SelectItem>
                {staff.map((member) => (
                    <SelectItem key={member.id} value={String(member.id)}>
                        {member.name}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}
