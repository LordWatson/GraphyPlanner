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
    TargetAccountData,
} from '@/components/post-editor/types';
import { EMPTY_CHANNEL } from '@/components/post-editor/types';
import type { PreviewTarget } from '@/components/social-post-preview';
import { liveChecklist } from '@/lib/live-checklist';
import { publishedCaption } from '@/lib/media-format';
import type { PlatformRegistry } from '@/lib/platforms';
import { capabilityOf } from '@/lib/platforms';

export type ChannelUsage = Record<number, { chars: number; tags: number }>;

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
}: {
    state: EditorState;
    post: PostData;
    targetAccounts: TargetAccountData[];
    knownAssets: Record<number, AssetData>;
    campaignOptions: CampaignOption[];
    registry: PlatformRegistry;
    canUpdate: boolean;
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
          })
        : post.checklist.items;

    // Each channel's preview carries its own caption, hashtags, link, first comment and format.
    const previewTargets: (PreviewTarget & { id: number })[] = canUpdate
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

    return {
        saved,
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
