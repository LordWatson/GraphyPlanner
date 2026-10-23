import { Repeat } from 'lucide-react';
import InputError from '@/components/input-error';
import { Panel } from '@/components/page';
import { usePostEditorContext } from '@/components/post-editor/editor/editor-context';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

const RECUR_NONE = '__none';

/**
 * Step M-5.8 — 06 Repeat & reuse: recur this post (the next occurrence is drafted, never
 * auto-posted) or flag it evergreen so Home suggests reposting it after a window.
 */
export function RepeatReusePanel({
    errors,
}: {
    errors: Record<string, string>;
}) {
    const { editor } = usePostEditorContext();
    const { state } = editor;

    return (
        <Panel
            eyebrow="06"
            title="Repeat & reuse"
            description="Repost this evergreen post later, or have it recur automatically."
            className="mt-5"
            bodyClassName="grid gap-5 sm:grid-cols-2"
        >
            <div className="grid content-start gap-2">
                <Label
                    htmlFor="recurrence"
                    className="flex items-center gap-1.5"
                >
                    <Repeat className="text-muted-foreground size-3.5" />
                    Repeat
                </Label>
                <Select
                    value={state.recurrence || RECUR_NONE}
                    onValueChange={(value) =>
                        editor.update({
                            recurrence: value === RECUR_NONE ? '' : value,
                        })
                    }
                >
                    <SelectTrigger id="recurrence">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value={RECUR_NONE}>
                            Doesn’t repeat
                        </SelectItem>
                        <SelectItem value="weekly">Weekly</SelectItem>
                        <SelectItem value="monthly">Monthly</SelectItem>
                    </SelectContent>
                </Select>
                <p className="text-muted-foreground text-xs">
                    After it publishes, the next one is drafted for you to
                    review — never posted automatically.
                </p>
                <InputError message={errors.recurrence} />
            </div>

            <div className="grid content-start gap-2">
                <Label className="flex items-center gap-2">
                    <Checkbox
                        checked={state.isRecyclable}
                        onCheckedChange={(checked) =>
                            editor.update({ isRecyclable: checked === true })
                        }
                    />
                    Mark as reusable (evergreen)
                </Label>
                {state.isRecyclable && (
                    <div className="grid gap-1.5">
                        <Label
                            htmlFor="recycle_after_days"
                            className="text-muted-foreground text-xs font-normal"
                        >
                            Suggest reposting this many days after it publishes
                        </Label>
                        <Input
                            id="recycle_after_days"
                            type="number"
                            min={1}
                            max={365}
                            value={state.recycleAfterDays}
                            onChange={(e) =>
                                editor.update({
                                    recycleAfterDays: e.target.value,
                                })
                            }
                            placeholder="e.g. 30"
                            className="w-28"
                        />
                        <InputError message={errors.recycle_after_days} />
                    </div>
                )}
            </div>
        </Panel>
    );
}
