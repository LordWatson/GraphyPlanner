import type {
    ChannelState,
    ChecklistFix,
    ChecklistItem,
    TargetAccountData,
} from '@/components/post-editor/types';
import { EMPTY_CHANNEL } from '@/components/post-editor/types';
import { publishedCaption } from '@/lib/media-format';
import type { PlatformRegistry } from '@/lib/platforms';
import { capabilityOf, resolveFormat } from '@/lib/platforms';

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

            return content.link.trim() !== '' &&
                !capability.link_field &&
                !capability.links_in_caption
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

    return serverItems.map((item) => {
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
}
