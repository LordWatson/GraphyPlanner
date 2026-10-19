import type { ChannelState } from '@/components/post-editor/types';
import { EMPTY_CHANNEL } from '@/components/post-editor/types';

/**
 * The hidden form fields for every selected channel, in one place so each target's schedule and
 * overrides share an index. An override left alone is sent empty (null — use the master); a
 * caption deliberately emptied is sent as the "none" marker.
 */
export function TargetFields({
    accountIds,
    schedule,
    channels,
}: {
    accountIds: number[];
    schedule: Record<number, { date: string; time: string }>;
    channels: Record<number, ChannelState>;
}) {
    if (accountIds.length === 0) {
        // Still send the field so saving clears the last channel.
        return <input type="hidden" name="targets" value="" />;
    }

    return (
        <>
            {accountIds.map((accountId, i) => {
                const channel = channels[accountId] ?? EMPTY_CHANNEL;
                const field = (name: string) => `targets[${i}][${name}]`;

                return (
                    <span key={accountId} hidden>
                        <input
                            type="hidden"
                            name={field('social_account_id')}
                            value={accountId}
                        />
                        <input
                            type="hidden"
                            name={field('scheduled_local_date')}
                            value={schedule[accountId]?.date ?? ''}
                        />
                        <input
                            type="hidden"
                            name={field('scheduled_local_time')}
                            value={schedule[accountId]?.time ?? ''}
                        />
                        <input
                            type="hidden"
                            name={field('caption_override')}
                            value={
                                channel.caption === null
                                    ? ''
                                    : channel.caption === ''
                                      ? '__none__'
                                      : channel.caption
                            }
                        />
                        <input
                            type="hidden"
                            name={field('hashtags_override')}
                            value={
                                channel.hashtags === null
                                    ? ''
                                    : JSON.stringify(channel.hashtags)
                            }
                        />
                        <input
                            type="hidden"
                            name={field('first_comment')}
                            value={channel.firstComment ?? ''}
                        />
                        <input
                            type="hidden"
                            name={field('link_url')}
                            value={channel.link ?? ''}
                        />
                        <input
                            type="hidden"
                            name={field('format')}
                            value={channel.format ?? ''}
                        />
                    </span>
                );
            })}
        </>
    );
}
