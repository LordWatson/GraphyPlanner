import { CalendarClock, MapPin, Music2, UserRound } from 'lucide-react';
import { DatePicker } from '@/components/date-picker';
import InputError from '@/components/input-error';
import { Panel } from '@/components/page';
import { CampaignField } from '@/components/post-editor/campaign-field';
import { usePostEditorContext } from '@/components/post-editor/editor/editor-context';
import { RepeatReusePanel } from '@/components/post-editor/editor/repeat-reuse-panel';
import { MusicField } from '@/components/post-editor/music-field';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

/** 04 Settings — campaign, approval mode, sound and location for this post. */
export function SettingsSection({
    errors,
}: {
    errors: Record<string, string>;
}) {
    const { editor, nav, page } = usePostEditorContext();
    const { state, view } = editor;

    return (
        <div ref={nav.sectionRef('settings')} className="scroll-mt-24">
            <Panel
                eyebrow="04"
                title="Post settings"
                description="Campaign, approval, sound and location."
                className={nav.flash('settings')}
                bodyClassName="grid gap-5 sm:grid-cols-2"
            >
                <div className="grid content-start gap-2">
                    <CampaignField
                        postId={page.post.id}
                        value={state.campaignId}
                        options={page.campaignOptions}
                        caption={state.caption}
                        hashtags={state.hashtags}
                        error={errors.campaign_id}
                        onChange={(campaignId) => editor.update({ campaignId })}
                        onAddHashtags={(tags) =>
                            editor.update({
                                hashtags: [...state.hashtags, ...tags],
                            })
                        }
                        onAddLink={(link) =>
                            editor.update({
                                caption:
                                    state.caption.trim() === ''
                                        ? link
                                        : `${state.caption.trimEnd()}\n\n${link}`,
                            })
                        }
                    />
                </div>
                <div className="grid content-start gap-2">
                    <Label htmlFor="approval_mode">Approval</Label>
                    <Select
                        value={state.approvalMode}
                        onValueChange={(approvalMode) =>
                            editor.update({ approvalMode })
                        }
                    >
                        <SelectTrigger id="approval_mode">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="client_required">
                                Client approval required
                            </SelectItem>
                            <SelectItem value="internal_only">
                                Internal approval only
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError message={errors.approval_mode} />
                </div>
                <div className="grid content-start gap-2">
                    <Label
                        htmlFor="music_search"
                        className="flex items-center gap-1.5"
                    >
                        <Music2 className="text-muted-foreground size-3.5" />
                        Sound
                    </Label>
                    <MusicField
                        postId={page.post.id}
                        clientId={page.post.client_id}
                        platforms={view.musicPlatforms}
                        instagramAccountId={
                            view.selectedAccounts.find(
                                (a) => a.platform === 'instagram',
                            )?.id ?? null
                        }
                        musicId={state.musicId}
                        musicName={state.musicName}
                        onChange={(track) =>
                            editor.update({
                                musicId: track.id,
                                musicName: track.name,
                            })
                        }
                    />
                </div>
                <div className="grid content-start gap-2">
                    <Label
                        htmlFor="location_name"
                        className="flex items-center gap-1.5"
                    >
                        <MapPin className="text-muted-foreground size-3.5" />
                        Location
                    </Label>
                    <Input
                        id="location_name"
                        name="location[name]"
                        dir="auto"
                        value={state.locationName}
                        onChange={(e) =>
                            editor.update({ locationName: e.target.value })
                        }
                        placeholder="e.g. Shoreditch, London"
                    />
                </div>
            </Panel>

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
                    <Label
                        htmlFor="due_at"
                        className="flex items-center gap-1.5"
                    >
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

            <RepeatReusePanel errors={errors} />
        </div>
    );
}

const UNASSIGNED = '__none';

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
