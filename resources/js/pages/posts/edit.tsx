import { Form, Head, Link } from '@inertiajs/react';
import {
    ArrowLeft,
    CheckCircle2,
    Circle,
    Facebook,
    Instagram,
    Linkedin,
    Lock,
    Music2,
    Share2,
    XCircle,
} from 'lucide-react';
import { useState } from 'react';
import PostController from '@/actions/App/Http/Controllers/PostController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { show as showClient } from '@/routes/clients';

type ChecklistItem = { key: string; label: string; passed: boolean };

type TargetData = {
    id: number;
    social_account_id: number;
    platform: string | null;
    handle: string | null;
    caption_limit: number | null;
    is_text_only_capable: boolean;
    scheduled_local_date: string | null;
    scheduled_local_time: string | null;
    scheduled_at_utc: string | null;
};

type ActivityLogData = {
    id: number;
    user_name: string | null;
    from_status: string | null;
    to_status: string;
    note: string | null;
    created_at: string | null;
};

type ApprovalData = {
    id: number;
    user_name: string | null;
    decision: string;
    comment: string | null;
    created_at: string | null;
};

type CommentData = {
    id: number;
    user_name: string | null;
    body: string;
    internal_only: boolean;
    created_at: string | null;
};

type PostData = {
    id: number;
    client_id: number;
    campaign_id: number | null;
    campaign_name: string | null;
    status: string;
    status_label: string;
    approval_mode: string;
    master_caption: string | null;
    hashtags: string[];
    music: Record<string, unknown> | null;
    location: Record<string, unknown> | null;
    checklist: { items: ChecklistItem[]; passed: boolean };
    assets: { id: number; url: string | null; original_filename: string | null; type: string | null }[];
    targets: TargetData[];
    activity_logs: ActivityLogData[];
    approvals: ApprovalData[];
    comments: CommentData[];
};

type TargetAccountData = {
    id: number;
    platform: string;
    handle: string;
    timezone: string;
};

type AvailableAssetData = {
    id: number;
    url: string | null;
    original_filename: string | null;
    type: string | null;
};

const platformIcon: Record<string, typeof Instagram> = {
    instagram: Instagram,
    tiktok: Music2,
    facebook: Facebook,
    linkedin: Linkedin,
};

const postStatusVariant: Record<string, 'success' | 'warning' | 'secondary' | 'outline' | 'destructive'> = {
    idea: 'outline',
    draft: 'secondary',
    internal_review: 'warning',
    waiting_client: 'warning',
    changes_requested: 'destructive',
    approved: 'success',
    scheduled: 'success',
    publishing: 'warning',
    published: 'success',
    failed: 'destructive',
    archived: 'outline',
};

export default function PostEdit({
    post,
    client,
    targetAccounts,
    availableAssets,
    allowedTransitions,
    can,
}: {
    post: PostData;
    client: { id: number; name: string };
    targetAccounts: TargetAccountData[];
    availableAssets: AvailableAssetData[];
    allowedTransitions: { value: string; label: string }[];
    can: { update: boolean; comment: boolean };
}) {
    const [caption, setCaption] = useState(post.master_caption ?? '');
    const [hashtagsText, setHashtagsText] = useState((post.hashtags ?? []).join(', '));
    const [selectedAssetIds, setSelectedAssetIds] = useState<number[]>(post.assets.map((asset) => asset.id));
    const [targetRows, setTargetRows] = useState<Record<number, { date: string; time: string }>>(
        Object.fromEntries(
            post.targets.map((target) => [
                target.social_account_id,
                { date: target.scheduled_local_date ?? '', time: target.scheduled_local_time ?? '' },
            ]),
        ),
    );
    const [musicName, setMusicName] = useState((post.music?.name as string) ?? '');
    const [locationName, setLocationName] = useState((post.location?.name as string) ?? '');

    const hashtags = hashtagsText
        .split(',')
        .map((tag) => tag.trim())
        .filter((tag) => tag.length > 0);

    const toggleTargetAccount = (accountId: number) => {
        setTargetRows((current) => {
            if (accountId in current) {
                const next = { ...current };
                delete next[accountId];

                return next;
            }

            return { ...current, [accountId]: { date: '', time: '' } };
        });
    };

    const toggleAsset = (assetId: number) => {
        setSelectedAssetIds((current) =>
            current.includes(assetId) ? current.filter((id) => id !== assetId) : [...current, assetId],
        );
    };

    const targetEntries = Object.entries(targetRows);

    return (
        <>
            <Head title={`Edit post — ${client.name}`} />
            <div className="mx-auto flex w-full max-w-5xl flex-col gap-6 p-4">
                <div className="flex flex-col gap-4">
                    <Link
                        href={showClient(client.id)}
                        className="inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground"
                    >
                        <ArrowLeft className="size-4" />
                        Back to {client.name}
                    </Link>
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <Heading title="Post editor" description={`Client: ${client.name}`} />
                        <div className="flex items-center gap-2">
                            <Badge variant={postStatusVariant[post.status] ?? 'outline'}>{post.status_label}</Badge>
                            {post.campaign_name && (
                                <span className="text-xs text-muted-foreground">{post.campaign_name}</span>
                            )}
                        </div>
                    </div>
                </div>

                {can.update && (
                    <Form
                        {...PostController.update.form(post.id)}
                        className="grid gap-4 rounded-lg border border-border bg-card p-4"
                    >
                        {({ processing, errors }) => (
                            <>
                                <div className="grid gap-1">
                                    <Label htmlFor="master_caption">Master caption</Label>
                                    <textarea
                                        id="master_caption"
                                        name="master_caption"
                                        rows={4}
                                        value={caption}
                                        onChange={(e) => setCaption(e.target.value)}
                                        className="border-input dark:bg-input/30 flex w-full rounded-md border bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px]"
                                    />
                                    <InputError message={errors.master_caption} />
                                </div>

                                <div className="grid gap-1">
                                    <Label htmlFor="hashtags_text">Hashtags (comma-separated)</Label>
                                    <Input
                                        id="hashtags_text"
                                        value={hashtagsText}
                                        onChange={(e) => setHashtagsText(e.target.value)}
                                        placeholder="#launch, #reels"
                                    />
                                    {hashtags.map((tag, i) => (
                                        <input key={i} type="hidden" name={`hashtags[${i}]`} value={tag} />
                                    ))}
                                </div>

                                <div className="grid gap-2">
                                    <Label>Target accounts</Label>
                                    <p className="text-xs text-muted-foreground">
                                        Accounts filtered to {client.name}. Each target keeps its own local schedule —
                                        two targets in different timezones never collapse to one shared time.
                                    </p>
                                    <div className="flex flex-wrap gap-2">
                                        {targetAccounts.map((account) => {
                                            const AccountIcon = platformIcon[account.platform] ?? Share2;
                                            const selected = account.id in targetRows;

                                            return (
                                                <button
                                                    key={account.id}
                                                    type="button"
                                                    onClick={() => toggleTargetAccount(account.id)}
                                                    className={`inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs transition-colors ${
                                                        selected
                                                            ? 'border-primary bg-primary/10 text-primary'
                                                            : 'border-border bg-muted/50 text-foreground'
                                                    }`}
                                                >
                                                    <AccountIcon className="size-3.5" />
                                                    {account.handle} · {account.timezone}
                                                </button>
                                            );
                                        })}
                                    </div>

                                    {targetEntries.length === 0 && (
                                        <p className="text-sm text-muted-foreground">No target accounts selected.</p>
                                    )}

                                    <div className="grid gap-2">
                                        {targetEntries.map(([accountIdStr, row], i) => {
                                            const accountId = Number(accountIdStr);
                                            const account = targetAccounts.find((a) => a.id === accountId);
                                            const AccountIcon = platformIcon[account?.platform ?? ''] ?? Share2;
                                            const limit = post.targets.find((t) => t.social_account_id === accountId)
                                                ?.caption_limit;

                                            return (
                                                <div
                                                    key={accountId}
                                                    className="grid grid-cols-1 gap-2 rounded-md border border-border bg-muted/20 p-3 sm:grid-cols-4 sm:items-end"
                                                >
                                                    <div className="flex items-center gap-2 text-sm sm:col-span-1">
                                                        <AccountIcon className="size-4 text-primary" />
                                                        <div className="flex flex-col">
                                                            <span>{account?.handle ?? accountId}</span>
                                                            <span className="text-xs tabular-nums text-muted-foreground">
                                                                {caption.length}
                                                                {limit ? ` / ${limit}` : ''} chars
                                                            </span>
                                                        </div>
                                                    </div>
                                                    <div className="grid gap-1">
                                                        <Label htmlFor={`target-date-${accountId}`}>Local date</Label>
                                                        <Input
                                                            id={`target-date-${accountId}`}
                                                            type="date"
                                                            value={row.date}
                                                            onChange={(e) =>
                                                                setTargetRows((current) => ({
                                                                    ...current,
                                                                    [accountId]: { ...current[accountId], date: e.target.value },
                                                                }))
                                                            }
                                                        />
                                                    </div>
                                                    <div className="grid gap-1">
                                                        <Label htmlFor={`target-time-${accountId}`}>Local time</Label>
                                                        <Input
                                                            id={`target-time-${accountId}`}
                                                            type="time"
                                                            value={row.time}
                                                            onChange={(e) =>
                                                                setTargetRows((current) => ({
                                                                    ...current,
                                                                    [accountId]: { ...current[accountId], time: e.target.value },
                                                                }))
                                                            }
                                                        />
                                                    </div>
                                                    <input type="hidden" name={`targets[${i}][social_account_id]`} value={accountId} />
                                                    <input type="hidden" name={`targets[${i}][scheduled_local_date]`} value={row.date} />
                                                    <input type="hidden" name={`targets[${i}][scheduled_local_time]`} value={row.time} />
                                                </div>
                                            );
                                        })}
                                    </div>
                                    <InputError message={errors['targets.0.social_account_id']} />
                                </div>

                                <div className="grid gap-2 sm:grid-cols-2">
                                    <div className="grid gap-1">
                                        <Label htmlFor="music_name">
                                            Music{' '}
                                            <Badge variant="outline" className="ml-1 align-middle">
                                                <Lock className="size-3" />
                                                Not available yet
                                            </Badge>
                                        </Label>
                                        <Input
                                            id="music_name"
                                            name="music[name]"
                                            value={musicName}
                                            onChange={(e) => setMusicName(e.target.value)}
                                            placeholder="Track / audio name"
                                        />
                                    </div>
                                    <div className="grid gap-1">
                                        <Label htmlFor="location_name">Location</Label>
                                        <Input
                                            id="location_name"
                                            name="location[name]"
                                            value={locationName}
                                            onChange={(e) => setLocationName(e.target.value)}
                                            placeholder="Location name"
                                        />
                                    </div>
                                </div>

                                <div className="grid gap-2">
                                    <Label>Media</Label>
                                    {availableAssets.length === 0 ? (
                                        <p className="text-sm text-muted-foreground">
                                            No assets on this client yet — add one from the client page first.
                                        </p>
                                    ) : (
                                        <div className="flex flex-wrap gap-2">
                                            {availableAssets.map((asset) => {
                                                const selected = selectedAssetIds.includes(asset.id);

                                                return (
                                                    <button
                                                        key={asset.id}
                                                        type="button"
                                                        onClick={() => toggleAsset(asset.id)}
                                                        className={`inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs transition-colors ${
                                                            selected
                                                                ? 'border-primary bg-primary/10 text-primary'
                                                                : 'border-border bg-muted/50 text-foreground'
                                                        }`}
                                                    >
                                                        {asset.original_filename ?? `Asset #${asset.id}`}
                                                    </button>
                                                );
                                            })}
                                        </div>
                                    )}
                                    {selectedAssetIds.map((id, i) => (
                                        <input key={id} type="hidden" name={`asset_ids[${i}]`} value={id} />
                                    ))}
                                </div>

                                <div>
                                    <Button type="submit" disabled={processing}>
                                        Save changes
                                    </Button>
                                </div>
                            </>
                        )}
                    </Form>
                )}

                <div className="grid gap-3 rounded-lg border border-border bg-card p-4">
                    <Heading
                        title="Pre-schedule checklist"
                        description="Re-validated server-side before any move to Scheduled"
                    />
                    <div className="grid gap-2">
                        {post.checklist.items.map((item) => (
                            <div key={item.key} className="flex items-center gap-2 text-sm">
                                {item.passed ? (
                                    <CheckCircle2 className="size-4 text-green-600 dark:text-green-500" />
                                ) : (
                                    <XCircle className="size-4 text-destructive" />
                                )}
                                <span className={item.passed ? '' : 'text-muted-foreground'}>{item.label}</span>
                            </div>
                        ))}
                    </div>
                    <Badge variant={post.checklist.passed ? 'success' : 'destructive'} className="w-fit">
                        {post.checklist.passed ? 'All checks passed' : 'Checklist incomplete'}
                    </Badge>
                </div>

                {allowedTransitions.length > 0 && (
                    <div className="grid gap-3 rounded-lg border border-border bg-card p-4">
                        <Heading title="Move status" description="Only legal, authorized transitions are shown" />
                        <div className="flex flex-wrap gap-2">
                            {allowedTransitions.map((transitionOption) => (
                                <Form key={transitionOption.value} {...PostController.transition.form(post.id)}>
                                    {({ processing, errors }) => (
                                        <div className="grid gap-1">
                                            <input type="hidden" name="to" value={transitionOption.value} />
                                            <Button
                                                type="submit"
                                                size="sm"
                                                variant={transitionOption.value === 'scheduled' && !post.checklist.passed ? 'outline' : 'default'}
                                                disabled={
                                                    processing ||
                                                    (transitionOption.value === 'scheduled' && !post.checklist.passed)
                                                }
                                            >
                                                {transitionOption.value === 'changes_requested' ? (
                                                    <Circle className="size-3.5" />
                                                ) : (
                                                    <CheckCircle2 className="size-3.5" />
                                                )}
                                                Move to {transitionOption.label}
                                            </Button>
                                            <InputError message={errors.to} />
                                        </div>
                                    )}
                                </Form>
                            ))}
                        </div>
                    </div>
                )}

                <div className="grid gap-3 rounded-lg border border-border bg-card p-4">
                    <Heading title="Comments" description="Internal notes are never visible to client reviewers" />
                    {can.comment && (
                        <Form
                            {...PostController.comment.form(post.id)}
                            resetOnSuccess
                            className="grid gap-2 rounded-md border border-dashed border-border p-3"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <textarea
                                        name="body"
                                        rows={2}
                                        placeholder="Add a comment…"
                                        className="border-input dark:bg-input/30 flex w-full rounded-md border bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px]"
                                    />
                                    <InputError message={errors.body} />
                                    <div className="flex items-center justify-between gap-2">
                                        <label className="flex items-center gap-1.5 text-xs text-muted-foreground">
                                            <input type="checkbox" name="internal_only" value="1" />
                                            Internal only
                                        </label>
                                        <Button type="submit" size="sm" disabled={processing}>
                                            Post comment
                                        </Button>
                                    </div>
                                </>
                            )}
                        </Form>
                    )}
                    <div className="grid gap-2">
                        {post.comments.length === 0 ? (
                            <p className="text-sm text-muted-foreground">No comments yet.</p>
                        ) : (
                            post.comments.map((c) => (
                                <div key={c.id} className="rounded-md border border-border bg-muted/20 p-2 text-sm">
                                    <div className="flex items-center justify-between gap-2">
                                        <span className="font-medium">{c.user_name ?? 'Unknown'}</span>
                                        <div className="flex items-center gap-2">
                                            {c.internal_only && <Badge variant="secondary">Internal</Badge>}
                                            <span className="text-xs text-muted-foreground">{c.created_at}</span>
                                        </div>
                                    </div>
                                    <p>{c.body}</p>
                                </div>
                            ))
                        )}
                    </div>
                </div>

                <div className="grid gap-3 rounded-lg border border-border bg-card p-4">
                    <Heading title="Activity log" description="Every status transition, oldest last" />
                    <div className="grid gap-2">
                        {post.activity_logs.length === 0 ? (
                            <p className="text-sm text-muted-foreground">No activity yet.</p>
                        ) : (
                            post.activity_logs.map((log) => (
                                <div key={log.id} className="flex items-center justify-between gap-2 text-sm">
                                    <span>
                                        {log.user_name ?? 'System'}: {log.from_status ?? '—'} → {log.to_status}
                                        {log.note ? ` — “${log.note}”` : ''}
                                    </span>
                                    <span className="text-xs text-muted-foreground">{log.created_at}</span>
                                </div>
                            ))
                        )}
                    </div>
                </div>
            </div>
        </>
    );
}
