import { AlertTriangle, Check, Circle, Wand2 } from 'lucide-react';
import type {
    ChannelState,
    ChecklistFix,
    ChecklistItem,
    TargetAccountData,
} from '@/components/post-editor/types';
import { EMPTY_CHANNEL } from '@/components/post-editor/types';
import { Button } from '@/components/ui/button';
import { publishedCaption } from '@/lib/media-format';
import type { PlatformRegistry } from '@/lib/platforms';
import { capabilityOf, resolveFormat } from '@/lib/platforms';
import { cn } from '@/lib/utils';

/** Short, scannable labels for the server's §6 checklist keys; unknown keys keep the server label. */
const shortLabels: Record<string, string> = {
    caption: 'Every channel has a caption',
    targets: 'At least one channel',
    schedule: 'Every channel has a date & time',
    media: 'Media attached',
    caption_length: 'Captions fit each channel',
    format: 'Formats fit the media',
    media_fit: 'Media within each channel’s limits',
    account_settings: 'Channel settings complete',
    hashtags: 'Hashtags suit each channel',
    links: 'Links will work where posted',
    first_comment: 'First comments supported',
    media_framing: 'Media framed for each channel',
    campaign_hashtags: 'Campaign hashtags included',
};

const URL = /https?:\/\/\S+/i;

type LiveState = {
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

export function ReadinessChecklist({
    items,
    note,
    onFix,
}: {
    items: ChecklistItem[];
    note?: string;
    /** Applies a one-click fix to the unsaved editor state (omit when read-only). */
    onFix?: (fix: ChecklistFix) => void;
}) {
    // Soft checks warn but never count against "ready". Passing soft checks stay out of the way.
    const required = items.filter((item) => !item.soft);
    const visible = items.filter((item) => !item.soft || !item.passed);
    const passedCount = required.filter((item) => item.passed).length;
    const allPassed = passedCount === required.length;

    return (
        <section className="border-border bg-card rounded-2xl border p-5">
            <div className="flex items-baseline justify-between gap-2">
                <h2 className="text-[15px] font-semibold">Ready to schedule</h2>
                <span
                    className={cn(
                        'font-mono text-xs tabular-nums',
                        allPassed ? 'text-success' : 'text-muted-foreground',
                    )}
                >
                    {passedCount}/{required.length}
                </span>
            </div>
            <div
                className="bg-muted mt-3 flex h-1 gap-0.5 overflow-hidden rounded-full"
                aria-hidden
            >
                {required.map((item) => (
                    <span
                        key={item.key}
                        className={cn(
                            'h-full flex-1 transition-colors',
                            item.passed ? 'bg-success' : 'bg-transparent',
                        )}
                    />
                ))}
            </div>
            <ul className="mt-4 grid gap-2.5">
                {visible.map((item) => (
                    <li key={item.key} className="grid gap-1.5 text-sm">
                        <span
                            className="flex items-center gap-2.5"
                            title={item.label}
                        >
                            {item.passed ? (
                                <span className="bg-success/15 text-success flex size-5 shrink-0 items-center justify-center rounded-full">
                                    <Check className="size-3" />
                                    <span className="sr-only">Done:</span>
                                </span>
                            ) : item.soft ? (
                                <span className="text-warning flex size-5 shrink-0 items-center justify-center">
                                    <AlertTriangle className="size-4" />
                                    <span className="sr-only">
                                        Worth a look:
                                    </span>
                                </span>
                            ) : (
                                <span className="text-muted-foreground flex size-5 shrink-0 items-center justify-center">
                                    <Circle className="size-4" />
                                    <span className="sr-only">To do:</span>
                                </span>
                            )}
                            <span
                                className={
                                    item.passed
                                        ? 'text-foreground'
                                        : 'text-muted-foreground'
                                }
                            >
                                {shortLabels[item.key] ?? item.label}
                            </span>
                        </span>
                        {!item.passed && item.detail && (
                            <ul className="text-muted-foreground ms-7.5 grid gap-1 text-xs leading-snug">
                                {item.detail.split('\n').map((line) => (
                                    <li key={line}>{line}</li>
                                ))}
                            </ul>
                        )}
                        {!item.passed &&
                            onFix &&
                            (item.fixes?.length ?? 0) > 0 && (
                                <div className="ms-7.5 flex flex-wrap gap-1.5">
                                    {item.fixes?.map((fix) => (
                                        <Button
                                            key={`${fix.action}-${fix.social_account_id ?? fix.platform}-${fix.value}`}
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            className="h-7 text-xs"
                                            onClick={() => onFix(fix)}
                                        >
                                            <Wand2 className="size-3.5" />
                                            {fix.label}
                                        </Button>
                                    ))}
                                </div>
                            )}
                    </li>
                ))}
            </ul>
            {note && (
                <p className="text-muted-foreground border-border/70 mt-4 border-t pt-3 text-xs">
                    {note}
                </p>
            )}
        </section>
    );
}
