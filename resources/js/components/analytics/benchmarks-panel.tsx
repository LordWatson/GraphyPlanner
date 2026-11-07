import { router } from '@inertiajs/react';
import { Plus, RefreshCw, Swords, Trash2 } from 'lucide-react';
import { useState } from 'react';

import { EmptyState, Panel } from '@/components/page';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    destroy as destroyProfile,
    store as storeProfile,
    sync as syncProfile,
} from '@/routes/clients/benchmarks';

export type BenchmarkProfile = {
    id: number;
    name: string;
    handle: string;
    platform: string;
    platform_label: string;
    has_data: boolean;
    followers: number | null;
    followers_change: number | null;
    post_count: number | null;
    avg_engagement: number | null;
    captured_on: string | null;
    last_error: string | null;
};

export type BenchmarksData = {
    entitled: boolean;
    window_days: number;
    us: {
        name: string;
        connected: boolean;
        followers: number | null;
        post_count: number | null;
        avg_engagement: number | null;
    };
    profiles: BenchmarkProfile[];
    takeaway: string | null;
};

/**
 * Step GTM-4.2 — how a brand compares to the profiles it tracks.
 *
 * **A gap is shown as a gap.** Every figure is nullable from the query down, and a missing one
 * renders as an em dash with the reason beside the row — never as 0, which would make a competitor
 * look worse than they are.
 */
export function BenchmarksPanel({
    clientId,
    benchmarks,
    platforms,
    canManage,
}: {
    clientId: number;
    benchmarks: BenchmarksData;
    platforms: { value: string; label: string }[];
    canManage: boolean;
}) {
    const [platform, setPlatform] = useState(platforms[0]?.value ?? '');
    const [handle, setHandle] = useState('');
    const [label, setLabel] = useState('');

    if (!benchmarks.entitled) {
        return (
            <Panel
                eyebrow="Benchmarks"
                title="How you compare"
                icon={Swords}
                description="Track the accounts you are measured against."
            >
                <EmptyState
                    compact
                    className="border-0"
                    icon={Swords}
                    title="Benchmarks are on a paid plan."
                    description="Track competitor profiles and see them beside your own figures, here and in every client report."
                />
            </Panel>
        );
    }

    return (
        <Panel
            eyebrow={`Last ${benchmarks.window_days} days`}
            title="How you compare"
            icon={Swords}
            description="Public figures only, and a dash wherever the platform did not report one."
        >
            <table className="w-full text-sm">
                <thead>
                    <tr className="text-muted-foreground border-border/70 border-b text-left">
                        <th className="py-2 font-medium">Profile</th>
                        <th className="py-2 text-right font-medium">
                            Followers
                        </th>
                        <th className="py-2 text-right font-medium">Posts</th>
                        <th className="py-2 text-right font-medium">
                            Interactions / post
                        </th>
                        {canManage && <th className="py-2" />}
                    </tr>
                </thead>
                <tbody>
                    <tr className="border-border/40 border-b">
                        <td className="py-2.5 font-semibold">
                            {benchmarks.us.name}
                            <span className="text-muted-foreground ml-2 font-mono text-[10px] tracking-widest uppercase">
                                you
                            </span>
                            {!benchmarks.us.connected && (
                                <span className="text-muted-foreground block text-xs">
                                    No Instagram account connected yet.
                                </span>
                            )}
                        </td>
                        <Figure value={benchmarks.us.followers} />
                        <Figure value={benchmarks.us.post_count} />
                        <Figure value={benchmarks.us.avg_engagement} />
                        {canManage && <td />}
                    </tr>

                    {benchmarks.profiles.map((profile) => (
                        <tr
                            key={profile.id}
                            className="border-border/40 border-b"
                        >
                            <td className="py-2.5">
                                {profile.name}
                                <span className="text-muted-foreground ml-2 text-xs">
                                    {profile.platform_label}
                                </span>
                                {!profile.has_data && (
                                    <span className="text-muted-foreground block text-xs">
                                        {profile.last_error ??
                                            'No readings yet — the first one lands overnight.'}
                                    </span>
                                )}
                            </td>
                            <Figure
                                value={profile.followers}
                                change={profile.followers_change}
                            />
                            <Figure value={profile.post_count} />
                            <Figure value={profile.avg_engagement} />
                            {canManage && (
                                <td className="py-2.5 text-right whitespace-nowrap">
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        aria-label={`Refresh ${profile.name}`}
                                        onClick={() =>
                                            router.post(
                                                syncProfile({
                                                    client: clientId,
                                                    profile: profile.id,
                                                }).url,
                                                {},
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        <RefreshCw className="size-4" />
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        aria-label={`Stop tracking ${profile.name}`}
                                        onClick={() =>
                                            router.delete(
                                                destroyProfile({
                                                    client: clientId,
                                                    profile: profile.id,
                                                }).url,
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        <Trash2 className="size-4" />
                                    </Button>
                                </td>
                            )}
                        </tr>
                    ))}
                </tbody>
            </table>

            {benchmarks.profiles.length === 0 && (
                <p className="text-muted-foreground text-sm">
                    Nothing tracked yet. Add a competitor below and the first
                    reading lands overnight.
                </p>
            )}

            {benchmarks.takeaway && (
                <p className="bg-muted/40 rounded-lg p-3 text-sm">
                    {benchmarks.takeaway}
                </p>
            )}

            {canManage && platforms.length > 0 && (
                <div className="flex flex-wrap items-end gap-2">
                    <Select value={platform} onValueChange={setPlatform}>
                        <SelectTrigger className="w-40">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {platforms.map((option) => (
                                <SelectItem
                                    key={option.value}
                                    value={option.value}
                                >
                                    {option.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <Input
                        value={handle}
                        onChange={(event) => setHandle(event.target.value)}
                        placeholder="@theircompetitor"
                        className="w-full sm:w-56"
                    />
                    <Input
                        value={label}
                        onChange={(event) => setLabel(event.target.value)}
                        placeholder="Label (optional)"
                        className="w-full sm:w-44"
                    />
                    <Button
                        variant="outline"
                        disabled={handle.trim() === ''}
                        onClick={() =>
                            router.post(
                                storeProfile({ client: clientId }).url,
                                { platform, handle, label },
                                {
                                    preserveScroll: true,
                                    onSuccess: () => {
                                        setHandle('');
                                        setLabel('');
                                    },
                                },
                            )
                        }
                    >
                        <Plus className="size-4" />
                        Track
                    </Button>
                </div>
            )}

            <p className="text-muted-foreground text-[13px]">
                Instagram only for now: it is the one network whose public
                figures we are allowed to read for an account we do not own.
            </p>
        </Panel>
    );
}

/** A figure, or an em dash when the platform did not report it. Never a zero we were not given. */
function Figure({
    value,
    change,
}: {
    value: number | null;
    change?: number | null;
}) {
    return (
        <td className="py-2.5 text-right font-mono text-xs tabular-nums">
            {value === null ? (
                <span className="text-muted-foreground">&mdash;</span>
            ) : (
                <>
                    {value.toLocaleString()}
                    {change !== null &&
                        change !== undefined &&
                        change !== 0 && (
                            <span className="text-muted-foreground ml-1.5">
                                {change > 0 ? '+' : ''}
                                {change.toLocaleString()}
                            </span>
                        )}
                </>
            )}
        </td>
    );
}
