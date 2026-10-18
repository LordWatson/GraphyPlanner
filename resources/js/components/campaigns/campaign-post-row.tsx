import type { InertiaLinkProps } from '@inertiajs/react';
import { Link } from '@inertiajs/react';
import { ArrowUpRight } from 'lucide-react';
import type { ReactNode } from 'react';
import type { CampaignPost } from '@/components/campaigns/types';
import { platformIcon } from '@/components/platform-icon';
import { StatusPill } from '@/components/status-pill';
import { formatSchedule } from '@/lib/dates';

/**
 * One post inside a campaign view: index, caption, channels, its first slot in local time, the
 * status pill. The whole row links to `href`; `trailing` adds actions after the pill.
 */
export function CampaignPostRow({
    post,
    index,
    href,
    trailing,
    leading,
    meta,
}: {
    leading?: ReactNode;
    post: Pick<
        CampaignPost,
        | 'id'
        | 'status'
        | 'status_label'
        | 'master_caption'
        | 'targets'
        | 'scheduled_local_date'
        | 'scheduled_local_time'
    >;
    index?: number;
    href: NonNullable<InertiaLinkProps['href']>;
    trailing?: ReactNode;
    meta?: ReactNode;
}) {
    return (
        <li className="hover:bg-muted/50 flex items-center gap-3 px-4 py-3.5 transition-colors sm:gap-4 sm:px-5">
            {leading}
            {index !== undefined && (
                <span className="text-muted-foreground hidden w-6 shrink-0 font-mono text-[11px] tabular-nums sm:inline">
                    {String(index + 1).padStart(2, '0')}
                </span>
            )}
            <Link
                href={href}
                className="group flex min-w-0 flex-1 items-center gap-3"
            >
                <span className="flex min-w-0 flex-1 flex-col gap-1">
                    <span className="group-hover:text-primary truncate text-sm font-medium">
                        {post.master_caption || 'Untitled post'}
                    </span>
                    <span className="text-muted-foreground flex min-w-0 flex-wrap items-center gap-x-3 gap-y-1 text-xs">
                        {post.targets.length === 0
                            ? 'No channels yet'
                            : post.targets.map((target, i) => {
                                  const Icon = platformIcon(target.platform);

                                  return (
                                      <span
                                          key={i}
                                          className="inline-flex items-center gap-1"
                                      >
                                          <Icon className="size-3.5" />
                                          {target.handle ?? target.platform}
                                      </span>
                                  );
                              })}
                        <span className="font-mono tabular-nums">
                            {formatSchedule(
                                post.scheduled_local_date,
                                post.scheduled_local_time,
                                'Unscheduled',
                            )}
                        </span>
                        {meta}
                    </span>
                </span>
                <ArrowUpRight className="text-muted-foreground hidden size-4 shrink-0 transition-transform group-hover:translate-x-0.5 group-hover:-translate-y-0.5 sm:block" />
            </Link>
            <StatusPill
                kind="post"
                value={post.status}
                label={post.status_label}
                className="shrink-0 whitespace-nowrap"
            />
            {trailing}
        </li>
    );
}
