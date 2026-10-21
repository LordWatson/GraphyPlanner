import { MapPin, Music2 } from 'lucide-react';
import InputError from '@/components/input-error';
import { Panel } from '@/components/page';
import { CampaignField } from '@/components/post-editor/campaign-field';
import { usePostEditorContext } from '@/components/post-editor/editor/editor-context';
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
        </div>
    );
}
