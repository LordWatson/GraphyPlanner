import { Lock } from 'lucide-react';
import type { ReactNode } from 'react';
import { AssetPreviewCarousel } from '@/components/asset-preview-carousel';
import { Panel } from '@/components/page';
import { platformIcon } from '@/components/platform-icon';
import type { PostData } from '@/components/post-editor/types';
import { formatLocalSchedule } from '@/components/post-editor/types';
import { StatusPill } from '@/components/status-pill';
import { Badge } from '@/components/ui/badge';

function ReadOnlyField({
    label,
    children,
}: {
    label: string;
    children: ReactNode;
}) {
    return (
        <div className="grid gap-1.5">
            <span className="eyebrow text-muted-foreground">{label}</span>
            {children}
        </div>
    );
}

/** What viewers without edit rights (or anyone, while the post is locked) see in place of the form. */
export function ReadOnlyContent({ post }: { post: PostData }) {
    const locked = post.status === 'scheduled' || post.status === 'published';

    return (
        <div className="grid gap-5">
            {locked && (
                <div className="border-border bg-muted/50 flex items-start gap-3 rounded-2xl border p-4 text-sm">
                    <Lock className="text-muted-foreground mt-0.5 size-4 shrink-0" />
                    <p>
                        <span className="font-medium">
                            {post.status_label} posts are locked.
                        </span>{' '}
                        <span className="text-muted-foreground">
                            Move it back to draft from the workflow above to
                            make changes.
                        </span>
                    </p>
                </div>
            )}
            <Panel eyebrow="01" title="Content">
                <div className="grid gap-5">
                    <ReadOnlyField label="Caption">
                        <p
                            dir="auto"
                            className="text-[15px] leading-relaxed whitespace-pre-wrap"
                        >
                            {post.master_caption || (
                                <span className="text-muted-foreground">
                                    No caption yet.
                                </span>
                            )}
                        </p>
                    </ReadOnlyField>
                    {post.hashtags.length > 0 && (
                        <ReadOnlyField label="Hashtags">
                            <div className="flex flex-wrap gap-1.5">
                                {post.hashtags.map((tag) => (
                                    <Badge
                                        key={tag}
                                        variant="secondary"
                                        dir="auto"
                                    >
                                        #{tag}
                                    </Badge>
                                ))}
                            </div>
                        </ReadOnlyField>
                    )}
                </div>
            </Panel>
            <Panel eyebrow="02" title="Channels & schedule">
                {post.targets.length > 0 ? (
                    <ul className="border-border divide-border/70 divide-y rounded-xl border">
                        {post.targets.map((target) => {
                            const Icon = platformIcon(target.platform);

                            return (
                                <li
                                    key={target.id}
                                    className="flex flex-wrap items-center gap-3 px-4 py-3 text-sm"
                                >
                                    <Icon className="text-foreground/70 size-4" />
                                    <span className="font-medium">
                                        {target.handle ?? '—'}
                                    </span>
                                    <span className="text-muted-foreground font-mono text-xs">
                                        {formatLocalSchedule(
                                            target.scheduled_local_date,
                                            target.scheduled_local_time,
                                        ) ?? 'No time set'}
                                    </span>
                                    {target.status !== 'pending' && (
                                        <StatusPill
                                            kind="postTarget"
                                            value={target.status}
                                            label={target.status_label}
                                            className="ml-auto"
                                        />
                                    )}
                                </li>
                            );
                        })}
                    </ul>
                ) : (
                    <p className="text-muted-foreground text-sm">
                        No channels selected.
                    </p>
                )}
            </Panel>
            <Panel eyebrow="03" title="Media">
                {post.assets.length > 0 ? (
                    <AssetPreviewCarousel assets={post.assets} />
                ) : (
                    <p className="text-muted-foreground text-sm">
                        No media attached.
                    </p>
                )}
            </Panel>
            {Boolean(post.music?.name || post.location?.name) && (
                <Panel eyebrow="04" title="Details">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <ReadOnlyField label="Sound">
                            <p className="text-sm">
                                {(post.music?.name as string) || '—'}
                            </p>
                        </ReadOnlyField>
                        <ReadOnlyField label="Location">
                            <p className="text-sm">
                                {(post.location?.name as string) || '—'}
                            </p>
                        </ReadOnlyField>
                    </div>
                </Panel>
            )}
        </div>
    );
}
