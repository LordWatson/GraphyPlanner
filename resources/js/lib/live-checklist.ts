import type {
    ChannelState,
    ChecklistFix,
    ChecklistItem,
    TargetAccountData,
} from '@/components/post-editor/types';
import { EMPTY_CHANNEL } from '@/components/post-editor/types';
import { publishedCaption } from '@/lib/media-format';
import type { PlatformRegistry } from '@/lib/platforms';
import { capabilityOf, resolveFormat, takesStoryExtras } from '@/lib/platforms';
import { hasStoryExtras, storyExtrasProblems } from '@/lib/story-extras';

const URL = /https?:\/\/\S+/i;

export type LiveState = {
    caption: string;
    selectedAccounts: TargetAccountData[];
    schedule: { date: string; time: string }[];
    mediaCount: number;
    /** `image`/`video` per item that publishes (a video post sends just the video). */
    mediaKinds?: string[];
    hashtags?: string[];
    campaignHashtags?: string[];
    channels?: Record<number, ChannelState>;
    link?: string;
    firstComment?: string;
    registry?: PlatformRegistry;
    /** Step S-1.4: who would post a Story by hand (for the live `story_handoff` warning). */
    storyHandoff?: {
        defaultRecipient: 'team' | 'client';
        canChooseRecipient: boolean;
        clientCanBeNotified: boolean;
        clientName: string;
        /** The post has an assignee (or creator) who can be the poster. */
        hasPoster: boolean;
    };
};

type Live = { passed: boolean; problems: string[]; fixes?: ChecklistFix[] };

/**
 * Mirrors `EvaluatePostChecklistAction` against the editor's *unsaved* state so the checklist
 * reacts while you type — per channel, against the capability registry, with the same reasons.
 * Media length/size/shape checks need the server's probe data, so those stay the last saved
 * result. The server re-runs the real check on save and before scheduling — this is only
 * feedback, never the gate.
 */
export function liveChecklist(
    serverItems: ChecklistItem[],
    state: LiveState,
): ChecklistItem[] {
    const registry = state.registry ?? {};
    const accounts = state.selectedAccounts;
    const hasTargets = accounts.length > 0;
    const kinds = state.mediaKinds ?? [];
    const tags = new Set(
        (state.hashtags ?? []).map((tag) =>
            tag.replace(/^#/, '').toLowerCase(),
        ),
    );

    const perChannel = (
        check: (account: TargetAccountData) => string | null,
    ): Live => {
        const problems = accounts
            .map(check)
            .filter((problem): problem is string => problem !== null);

        return {
            passed: problems.length === 0,
            problems: [...new Set(problems)],
        };
    };

    const channel = (account: TargetAccountData) =>
        state.channels?.[account.id] ?? EMPTY_CHANNEL;
    const name = (account: TargetAccountData) =>
        `${capabilityOf(registry, account.platform).label || account.platform_label} (${account.handle})`;
    const effective = (account: TargetAccountData) => {
        const own = channel(account);

        return {
            caption: own.caption ?? state.caption,
            hashtags: own.hashtags ?? state.hashtags ?? [],
            link: own.link ?? state.link ?? '',
            firstComment: own.firstComment ?? state.firstComment ?? '',
        };
    };

    // Step S-1.4: a Story with extras is posted by hand (mirror of `PostTarget::needsHandoff()`).
    const isAssisted = (account: TargetAccountData) =>
        takesStoryExtras(
            capabilityOf(registry, account.platform),
            channel(account).format,
        ) && hasStoryExtras(channel(account).storyExtras);
    const assisted = accounts.filter(isAssisted);

    const formatFixes: ChecklistFix[] = [];

    const live: Record<string, Live> = {
        caption: hasTargets
            ? perChannel((account) =>
                  effective(account).caption.trim() === ''
                      ? `${name(account)} has no caption.`
                      : null,
              )
            : {
                  passed: state.caption.trim() !== '',
                  problems:
                      state.caption.trim() === ''
                          ? ['The caption is empty.']
                          : [],
              },
        targets: { passed: hasTargets, problems: [] },
        schedule: {
            passed:
                hasTargets &&
                state.schedule.every(
                    (row) => row.date !== '' && row.time !== '',
                ),
            problems: [],
        },
        media: {
            passed:
                state.mediaCount > 0 ||
                (hasTargets &&
                    accounts.every((account) => account.is_text_only_capable)),
            problems: [],
        },
        caption_length: perChannel((account) => {
            const capability = capabilityOf(registry, account.platform);
            const content = effective(account);
            const length = publishedCaption(
                content.caption,
                content.hashtags,
                capability,
                content.link,
            ).length;

            return length > capability.caption_limit
                ? `${name(account)}: ${length.toLocaleString()}/${capability.caption_limit.toLocaleString()} characters.`
                : null;
        }),
        format: perChannel((account) => {
            if (kinds.length === 0) {
                return null;
            }

            const capability = capabilityOf(registry, account.platform);
            const format = resolveFormat(
                capability,
                channel(account).format,
                kinds,
            );

            if (!format) {
                return null;
            }

            const fits = (candidate: typeof format) =>
                kinds.length >= candidate.min_items &&
                kinds.length <= candidate.max_items &&
                kinds.every((kind) =>
                    candidate.media.includes(kind as 'image' | 'video'),
                );

            if (fits(format)) {
                return null;
            }

            capability.formats
                .filter(
                    (candidate) =>
                        candidate.key !== format.key && fits(candidate),
                )
                .forEach((candidate) =>
                    formatFixes.push({
                        action: 'format',
                        social_account_id: account.id,
                        value: candidate.key,
                        label: `Post to ${name(account)} as a ${candidate.label.toLowerCase()}`,
                    }),
                );

            return `${name(account)}: a ${format.label.toLowerCase()} doesn’t fit this media.`;
        }),
        account_settings: perChannel((account) => {
            const capability = capabilityOf(registry, account.platform);
            const missing = (account.missing_settings ?? []).map(
                (key) => capability.settings[key]?.label ?? key,
            );

            return missing.length > 0
                ? `${name(account)}: add the ${missing.join(', ').toLowerCase()} in the account’s settings.`
                : null;
        }),
        hashtags: perChannel((account) => {
            const capability = capabilityOf(registry, account.platform);
            const count = effective(account).hashtags.length;

            if (count > 0 && !capability.hashtags) {
                return `${capability.label} doesn’t use hashtags — they won’t be posted there.`;
            }

            return count > capability.max_hashtags
                ? `${name(account)}: ${count} hashtags (works best with up to ${capability.max_hashtags}).`
                : null;
        }),
        links: perChannel((account) => {
            const capability = capabilityOf(registry, account.platform);
            const content = effective(account);

            if (!capability.links_in_caption && URL.test(content.caption)) {
                return `${name(account)}: links in captions aren’t clickable — use the link in bio instead.`;
            }

            // Step S-1.3: a Story with a link sticker does carry a link — a person adds it.
            return content.link.trim() !== '' &&
                !capability.link_field &&
                !capability.links_in_caption &&
                !(isAssisted(account) && channel(account).storyExtras?.link)
                ? `${name(account)}: the link won’t be posted — ${capability.label} doesn’t show links on posts.`
                : null;
        }),
        first_comment: perChannel((account) => {
            const capability = capabilityOf(registry, account.platform);

            return effective(account).firstComment.trim() !== '' &&
                !capability.first_comment
                ? `${capability.label} doesn’t support first comments — it won’t be posted there.`
                : null;
        }),
        campaign_hashtags: {
            passed: (state.campaignHashtags ?? []).every((tag) =>
                tags.has(tag.replace(/^#/, '').toLowerCase()),
            ),
            problems: [],
        },
    };
    live.format.fixes = formatFixes;

    const handoff = state.storyHandoff;
    const storyProblems = assisted.flatMap((account) =>
        storyExtrasProblems(channel(account).storyExtras).map(
            (problem) => `${name(account)}: ${problem}`,
        ),
    );
    const handoffProblems = handoff
        ? assisted
              .map((account) => {
                  const recipient = handoff.canChooseRecipient
                      ? (channel(account).handoffRecipient ??
                        handoff.defaultRecipient)
                      : 'team';

                  if (recipient === 'client') {
                      return handoff.clientCanBeNotified
                          ? null
                          : `${name(account)}: ${handoff.clientName} has no contact to send the story to, so your team will be asked instead.`;
                  }

                  return handoff.hasPoster
                      ? null
                      : `${name(account)}: no one is assigned, so everyone on the team will be asked.`;
              })
              .filter((problem): problem is string => problem !== null)
        : [];

    live.story_extras = {
        passed: storyProblems.length === 0,
        problems: storyProblems,
    };
    live.story_handoff = {
        passed: handoffProblems.length === 0,
        problems: handoffProblems,
    };

    const merged = serverItems
        // The two Story items only exist while the post has a Story that is posted by hand.
        .filter(
            (item) => assisted.length > 0 || !STORY_ITEMS.includes(item.key),
        )
        .map((item) => {
            const result = live[item.key];

            if (!result) {
                return item;
            }

            return {
                ...item,
                passed: result.passed,
                detail:
                    result.problems.length > 0
                        ? result.problems.join('\n')
                        : undefined,
                fixes: result.passed ? undefined : (result.fixes ?? item.fixes),
            };
        });

    if (assisted.length === 0) {
        return merged;
    }

    // Added before the first save has told the server there is one.
    const missing: ChecklistItem[] = [
        {
            key: 'story_extras',
            label: 'Story extras are complete',
            passed: live.story_extras.passed,
            detail: storyProblems.join('\n') || undefined,
        },
        {
            key: 'story_handoff',
            label: 'Someone can post this by hand',
            passed: live.story_handoff.passed,
            soft: true,
            detail: handoffProblems.join('\n') || undefined,
        },
    ].filter((item) => !merged.some((existing) => existing.key === item.key));

    return [...merged, ...missing];
}

const STORY_ITEMS = ['story_extras', 'story_handoff'];
