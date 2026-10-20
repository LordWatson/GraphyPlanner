import { ChevronDown, MapPin, Music2 } from 'lucide-react';
import InputError from '@/components/input-error';
import { Panel } from '@/components/page';
import { CampaignField } from '@/components/post-editor/campaign-field';
import { usePostEditorContext } from '@/components/post-editor/editor/editor-context';
import { approvalLabels } from '@/components/post-editor/editor/editor-state';
import { MusicField } from '@/components/post-editor/music-field';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { cn } from '@/lib/utils';

/** The folded "Post settings": each value as a ruled spec cell, one click from editing. */
function SettingsSummary({
    items,
    onOpen,
}: {
    items: { label: string; value: string; muted: boolean }[];
    onOpen: () => void;
}) {
    return (
        <button
            type="button"
            onClick={onOpen}
            className="hover:bg-muted/50 focus-visible:ring-ring/50 -m-2 grid w-[calc(100%+1rem)] grid-cols-2 gap-x-6 gap-y-4 rounded-xl p-2 text-start transition-colors outline-none focus-visible:ring-[3px] sm:grid-cols-4"
        >
            {items.map((item) => (
                <span key={item.label} className="grid min-w-0 gap-1">
                    <span className="eyebrow text-muted-foreground">
                        {item.label}
                    </span>
                    <span
                        dir="auto"
                        className={cn(
                            'truncate text-sm',
                            item.muted
                                ? 'text-muted-foreground'
                                : 'font-medium',
                        )}
                    >
                        {item.value}
                    </span>
                </span>
            ))}
        </button>
    );
}

/** 04 Post settings — campaign, approval mode, sound and location; folded to a summary. */
export function SettingsSection({
    errors,
}: {
    errors: Record<string, string>;
}) {
    const { editor, nav, page } = usePostEditorContext();
    const { state, view } = editor;
    const open = nav.settingsOpen;

    return (
        <Collapsible
            open={open}
            onOpenChange={nav.setSettingsOpen}
            ref={nav.sectionRef('settings')}
            className="scroll-mt-24"
        >
            <Panel
                eyebrow="04"
                title="Post settings"
                className={nav.flash('settings')}
                actions={
                    <CollapsibleTrigger asChild>
                        <Button type="button" variant="ghost" size="sm">
                            {open ? 'Done' : 'Edit'}
                            <ChevronDown
                                className={cn(
                                    'transition-transform',
                                    open && 'rotate-180',
                                )}
                            />
                        </Button>
                    </CollapsibleTrigger>
                }
            >
                {!open && (
                    <SettingsSummary
                        onOpen={() => nav.setSettingsOpen(true)}
                        items={[
                            {
                                label: 'Campaign',
                                value:
                                    view.selectedCampaign?.name ??
                                    'No campaign',
                                muted: !view.selectedCampaign,
                            },
                            {
                                label: 'Approval',
                                value:
                                    approvalLabels[state.approvalMode] ??
                                    state.approvalMode,
                                muted: false,
                            },
                            {
                                label: 'Sound',
                                value: state.musicName || 'No sound',
                                muted: !state.musicName,
                            },
                            {
                                label: 'Location',
                                value: state.locationName || 'No location',
                                muted: !state.locationName,
                            },
                        ]}
                    />
                )}
                {/* Kept mounted while folded so every field still submits. */}
                <CollapsibleContent
                    forceMount
                    className="data-[state=closed]:hidden"
                >
                    <div className="grid gap-5 sm:grid-cols-2">
                        <div className="grid content-start gap-2">
                            <CampaignField
                                postId={page.post.id}
                                value={state.campaignId}
                                options={page.campaignOptions}
                                caption={state.caption}
                                hashtags={state.hashtags}
                                error={errors.campaign_id}
                                onChange={(campaignId) =>
                                    editor.update({ campaignId })
                                }
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
                                    editor.update({
                                        locationName: e.target.value,
                                    })
                                }
                                placeholder="e.g. Shoreditch, London"
                            />
                        </div>
                    </div>
                </CollapsibleContent>
            </Panel>
        </Collapsible>
    );
}
