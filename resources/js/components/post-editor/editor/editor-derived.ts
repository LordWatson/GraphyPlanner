import type { CampaignOption } from '@/components/post-editor/campaign-field';
import type { EditorState } from '@/components/post-editor/editor/editor-state';
import {
    mediaKindsOf,
    normalizeEditorState,
} from '@/components/post-editor/editor/editor-state';
import type {
    AssetData,
    ChecklistItem,
    PostData,
    StoryHandoffOptions,
    TargetAccountData,
} from '@/components/post-editor/types';
import { EMPTY_CHANNEL } from '@/components/post-editor/types';
import type { Target as PreviewPanelTarget } from '@/components/post-editor/preview/preview-parts';
import { liveChecklist } from '@/lib/live-checklist';
import { publishedCaption } from '@/lib/media-format';
import type { PlatformRegistry } from '@/lib/platforms';
import { capabilityOf, takesStoryExtras } from '@/lib/platforms';
import { hasStoryExtras, parseStoryExtras } from '@/lib/story-extras';

export type ChannelUsage = Record<number, { chars: number; tags: number }>;

const NO_STORY_HANDOFF: StoryHandoffOptions = {
    default_recipient: 'team',
    can_choose_recipient: false,
    client_can_be_notified: false,
    posters: [],
    creator_id: null,
    window_minutes: 120,
};

/** The team member a Story's reminder goes to: the assignee, else the creator, else nobody. */
function storyPosterId(
    options: StoryHandoffOptions,
    assigneeId: string,
): number | null {
    const isPoster = (id: number | null) =>
        id !== null && options.posters.some((poster) => poster.id === id);
    const assignee = assigneeId ? Number(assigneeId) : null;

    return isPoster(assignee)
        ? assignee
        : isPoster(options.creator_id)
          ? options.creator_id
          : null;
}

/**
 * Step I-4.6 / M-1.6 — everything the editor shows that follows from its state: the selected
 * accounts and media, per-channel usage, what the checklist and preview see. Pure, so it's
 * unit-tested without rendering the editor.
 */
export function deriveEditorView({
    state,
    post,
    targetAccounts,
    knownAssets,
    campaignOptions,
    registry,
    canUpdate,
    storyHandoff = NO_STORY_HANDOFF,
    clientName = 'The client',
}: {
    state: EditorState;
    post: PostData;
    targetAccounts: TargetAccountData[];
    knownAssets: Record<number, AssetData>;
    campaignOptions: CampaignOption[];
    registry: PlatformRegistry;
    canUpdate: boolean;
    /** Step S-1.4: who can post a Story by hand (the live `story_handoff` check reads it). */
    storyHandoff?: StoryHandoffOptions;
    clientName?: string;
}) {
    const saved = normalizeEditorState(state);
    const selectedAccounts = Object.keys(state.schedule)
        .map((id) => targetAccounts.find((a) => a.id === Number(id)))
        .filter((a): a is TargetAccountData => Boolean(a));
    const selectedAssets = state.assetIds
        .map((id) => knownAssets[id])
        .filter((asset): asset is AssetData => Boolean(asset));
    const mediaKinds = mediaKindsOf(selectedAssets);

    // Step 1.8.4 — music search only exists for Instagram/TikTok targets.
    const musicPlatforms = [
        ...new Set(
            selectedAccounts
                .map((a) => a.platform)
                .filter((p) => p === 'instagram' || p === 'tiktok'),
        ),
    ];

    const checklistItems: ChecklistItem[] = canUpdate
        ? liveChecklist(post.checklist.items, {
              caption: state.caption,
              selectedAccounts,
              schedule: Object.values(state.schedule),
              mediaCount: state.assetIds.length,
              mediaKinds,
              hashtags: state.hashtags,
              campaignHashtags:
                  campaignOptions.find(
                      (option) => String(option.id) === state.campaignId,
                  )?.hashtags ?? [],
              channels: saved.channels,
              link: state.link,
              firstComment: state.firstComment,
              registry,
              storyHandoff: {
                  defaultRecipient: storyHandoff.default_recipient,
                  canChooseRecipient: storyHandoff.can_choose_recipient,
                  clientCanBeNotified: storyHandoff.client_can_be_notified,
                  clientName,
                  hasPoster:
                      storyPosterId(storyHandoff, state.assigneeId) !== null,
              },
          })
        : post.checklist.items;

    // Each channel's preview carries its own caption, hashtags, link, first comment and format.
    const previewTargets: PreviewPanelTarget[] = canUpdate
        ? selectedAccounts.map((a) => {
              const channel = saved.channels[a.id] ?? EMPTY_CHANNEL;

              return {
                  id: a.id,
                  platform: a.platform,
                  handle: a.handle,
                  display_name: a.display_name,
                  caption: channel.caption,
                  hashtags: channel.hashtags,
                  link: channel.link ?? (state.link || null),
                  firstComment:
                      channel.firstComment ?? (state.firstComment || null),
                  format: channel.format,
                  storyExtras: channel.storyExtras,
              };
          })
        : post.targets.map((t) => ({
              id: t.social_account_id,
              platform: t.platform ?? '',
              handle: t.handle ?? '',
              display_name: t.display_name,
              format: t.format,
              link: t.link_url ?? post.link_url,
              firstComment: t.first_comment ?? post.first_comment,
              publishedCaption: t.published_caption,
              storyExtras: parseStoryExtras(t.story_extras),
          }));

    // Per-channel usage for the schedule rows: the caption as it publishes there.
    const channelUsage: ChannelUsage = Object.fromEntries(
        selectedAccounts.map((account) => {
            const channel = saved.channels[account.id] ?? EMPTY_CHANNEL;
            const tags = channel.hashtags ?? state.hashtags;

            return [
                account.id,
                {
                    chars: publishedCaption(
                        channel.caption ?? state.caption,
                        tags,
                        capabilityOf(registry, account.platform),
                        channel.link ?? state.link,
                    ).length,
                    tags: tags.length,
                },
            ];
        }),
    );

    const altTextPlatforms = [
        ...new Set(
            selectedAccounts
                .map((account) => capabilityOf(registry, account.platform))
                .filter((capability) => capability.alt_text)
                .map((capability) => capability.label),
        ),
    ];

    // Step S-5.1: the channels that are a Story with extras, so posted by hand.
    const assistedAccountIds = selectedAccounts
        .filter((account) => {
            const channel = saved.channels[account.id] ?? EMPTY_CHANNEL;

            return (
                takesStoryExtras(
                    capabilityOf(registry, account.platform),
                    channel.format,
                ) && hasStoryExtras(channel.storyExtras)
            );
        })
        .map((account) => account.id);

    return {
        saved,
        assistedAccountIds,
        selectedAccounts,
        selectedAssets,
        mediaKinds,
        musicPlatforms,
        checklistItems,
        previewTargets,
        channelUsage,
        altTextPlatforms,
        selectedCampaign: campaignOptions.find(
            (option) => String(option.id) === state.campaignId,
        ),
    };
}

export type EditorView = ReturnType<typeof deriveEditorView>;
