import { Form, Head, Link } from '@inertiajs/react';
import {
    ArrowLeft,
    ArrowRight,
    CheckCircle2,
    ChevronDown,
    Circle,
    Facebook,
    History,
    Instagram,
    Linkedin,
    Lock,
    MessageSquareQuote,
    Music2,
    Share2,
    User,
    XCircle,
} from 'lucide-react';
import { useState } from 'react';
import PostController from '@/actions/App/Http/Controllers/PostController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';
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
    review_message: string | null;
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

function formatStatusLabel(status: string | null): string {
    if (!status) {
        return '—';
    }

    return status
        .split('_')
        .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
        .join(' ');
}

function CommentEntry({ comment }: { comment: CommentData }) {
    const [open, setOpen] = useState(false);
    const body = comment.body ?? '';
    const preview = body.slice(0, 80);
    const isTruncated = body.length > 80;

    return (
        <Collapsible open={open} onOpenChange={setOpen} className="rounded-md border border-border bg-muted/20">
            <CollapsibleTrigger asChild>
                <button
                    type="button"
                    className="flex w-full items-center justify-between gap-2 p-2.5 text-left text-sm hover:bg-muted/40"
                >
                    <div className="flex min-w-0 flex-1 items-center gap-2">
                        <User className="size-4 shrink-0 text-muted-foreground" />
                        <span className="font-medium">{comment.user_name ?? 'Unknown'}</span>
                        {comment.internal_only && <Badge variant="secondary">Internal</Badge>}
                        <span className="hidden min-w-0 truncate text-xs text-muted-foreground sm:inline">
                            <MessageSquareQuote className="mr-1 inline size-3.5" />
                            {preview}
                            {isTruncated ? '…' : ''}
                        </span>
                    </div>
                    <div className="flex shrink-0 items-center gap-2">
                        <span className="text-xs text-muted-foreground">{comment.created_at}</span>
                        <ChevronDown className={cn('size-4 text-muted-foreground transition-transform', open && 'rotate-180')} />
                    </div>
                </button>
            </CollapsibleTrigger>
            <CollapsibleContent className="border-t border-border/60 px-2.5 py-2 text-sm">
                <div className="flex items-start gap-2">
                    <MessageSquareQuote className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                    <p className="whitespace-pre-wrap text-muted-foreground">{comment.body}</p>
                </div>
            </CollapsibleContent>
        </Collapsible>
    );
}

function ActivityLogEntry({ log }: { log: ActivityLogData }) {
    const [open, setOpen] = useState(false);
    const hasNote = Boolean(log.note && log.note.trim().length > 0);
    const preview = hasNote ? (log.note as string).slice(0, 80) : null;
    const isTruncated = hasNote && (log.note as string).length > 80;

    return (
        <Collapsible open={open} onOpenChange={setOpen} className="rounded-md border border-border bg-muted/20">
            <CollapsibleTrigger asChild>
                <button
                    type="button"
                    className="flex w-full items-center justify-between gap-2 p-2.5 text-left text-sm hover:bg-muted/40"
                >
                    <div className="flex min-w-0 flex-1 items-center gap-2">
                        <User className="size-4 shrink-0 text-muted-foreground" />
                        <span className="font-medium">{log.user_name ?? 'System'}</span>
                        <Badge variant={postStatusVariant[log.from_status ?? ''] ?? 'outline'} className="hidden sm:inline-flex">
                            {formatStatusLabel(log.from_status)}
                        </Badge>
                        <ArrowRight className="size-3.5 shrink-0 text-muted-foreground" />
                        <Badge variant={postStatusVariant[log.to_status] ?? 'outline'}>{formatStatusLabel(log.to_status)}</Badge>
                        {hasNote && (
                            <span className="hidden min-w-0 truncate text-xs text-muted-foreground sm:inline">
                                <MessageSquareQuote className="mr-1 inline size-3.5" />
                                {preview}
                                {isTruncated ? '…' : ''}
                            </span>
                        )}
                    </div>
                    <div className="flex shrink-0 items-center gap-2">
                        <span className="text-xs text-muted-foreground">{log.created_at}</span>
                        {hasNote && (
                            <ChevronDown className={cn('size-4 text-muted-foreground transition-transform', open && 'rotate-180')} />
                        )}
                    </div>
                </button>
            </CollapsibleTrigger>
            {hasNote && (
                <CollapsibleContent className="border-t border-border/60 px-2.5 py-2 text-sm">
                    <div className="flex items-start gap-2">
                        <MessageSquareQuote className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                        <p className="whitespace-pre-wrap text-muted-foreground">{log.note}</p>
                    </div>
                </CollapsibleContent>
            )}
        </Collapsible>
    );
}

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
    // const [reviewMessage, setReviewMessage] = useState(post.review_message ?? ''); // Message to client — commented out for now, may be re-added later.
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
                </div>

                <div className="relative overflow-hidden rounded-xl border border-border p-6 shadow-lg shadow-primary/10">
                    <span
                        aria-hidden
                        className="pointer-events-none absolute inset-0 -z-10 bg-gradient-brand opacity-[0.14]"
                    />
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <div className="flex flex-col gap-1">
                            <h1 className="text-2xl font-bold tracking-tight sm:text-3xl">
                                <span className="text-gradient-brand">Post editor</span>
                            </h1>
                            <p className="max-w-xl text-sm text-muted-foreground">Client: {client.name}</p>
                        </div>
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

                                {/* Message to client — commented out for now, may be re-added later.
                                <div className="grid gap-1">
                                    <Label htmlFor="review_message">Message to client</Label>
                                    <textarea
                                        id="review_message"
                                        name="review_message"
                                        rows={3}
                                        value={reviewMessage}
                                        onChange={(e) => setReviewMessage(e.target.value)}
                                        placeholder="Optional note shown to the client alongside the review request email..."
                                        className="border-input dark:bg-input/30 flex w-full rounded-md border bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px]"
                                    />
                                    <InputError message={errors.review_message} />
                                </div>
                                */}

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
                    <Heading
                        title="Comments"
                        description="Internal notes are never visible to client reviewers — click a comment to see full details"
                    />
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
                            <p className="flex items-center gap-1.5 text-sm text-muted-foreground">
                                <MessageSquareQuote className="size-4" />
                                No comments yet.
                            </p>
                        ) : (
                            post.comments.map((c) => <CommentEntry key={c.id} comment={c} />)
                        )}
                    </div>
                </div>

                <div className="grid gap-3 rounded-lg border border-border bg-card p-4">
                    <Heading
                        title="Activity log"
                        description="Every status transition, oldest last — click an entry to see full details"
                    />
                    <div className="grid gap-2">
                        {post.activity_logs.length === 0 ? (
                            <p className="flex items-center gap-1.5 text-sm text-muted-foreground">
                                <History className="size-4" />
                                No activity yet.
                            </p>
                        ) : (
                            post.activity_logs.map((log) => <ActivityLogEntry key={log.id} log={log} />)
                        )}
                    </div>
                </div>
            </div>
        </>
    );
}
