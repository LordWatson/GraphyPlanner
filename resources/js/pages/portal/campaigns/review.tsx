import { Head, Link, router } from '@inertiajs/react';
import {
    ArrowLeft,
    ArrowRight,
    CheckCheck,
    CheckCircle2,
    ChevronLeft,
    MessageSquareWarning,
    PartyPopper,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import type { PreviewAsset } from '@/components/asset-preview-carousel';
import type { CampaignTimeline } from '@/components/campaigns/types';
import InputError from '@/components/input-error';
import { EmptyState, Page, PageHeader } from '@/components/page';
import { SocialPostPreview } from '@/components/social-post-preview';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { formatSchedule } from '@/lib/dates';
import type { MediaFormat } from '@/lib/media-format';
import { cn } from '@/lib/utils';
import { show as portalCampaign } from '@/routes/portal/campaigns';
import { submit as submitReview } from '@/routes/portal/campaigns/review';

type ReviewPost = {
    id: number;
    master_caption: string | null;
    review_message: string | null;
    hashtags: string[];
    music: { name?: string } | null;
    location: { name?: string } | null;
    media_format: MediaFormat;
    seen_at: string;
    assets: PreviewAsset[];
    targets: {
        id: number;
        platform: string | null;
        handle: string | null;
        scheduled_local_date: string | null;
        scheduled_local_time: string | null;
        published_caption?: string;
        first_comment?: string | null;
        format?: string | null;
    }[];
};

type Decision = { decision: 'approved' | 'changes_requested'; comment: string };

/**
 * Step C-5.2 — "Review this campaign": one post at a time with its preview, a decision each, and
 * "Approve all remaining" once you've seen enough. Nothing is sent until "Send decisions"; each
 * decision then goes through the normal approval path, and a post the studio changed meanwhile
 * is reported back rather than approved blind. Thumb-friendly at 375px.
 */
export default function PortalCampaignReview({
    campaign,
    posts,
}: {
    campaign: { id: number; name: string; timeline: CampaignTimeline };
    posts: ReviewPost[];
}) {
    const [index, setIndex] = useState(0);
    const [decisions, setDecisions] = useState<Record<number, Decision>>({});
    const [note, setNote] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [sending, setSending] = useState(false);
    const post = posts[index];
    const decided = Object.keys(decisions).length;
    const current = post ? decisions[post.id] : undefined;
    const target = useMemo(
        () => post?.targets.find((item) => item.platform) ?? null,
        [post],
    );

    const decide = (decision: Decision['decision']) => {
        if (!post) {
            return;
        }

        if (decision === 'changes_requested' && note.trim() === '') {
            setErrors({
                note: 'Tell us what to change so we can get it right.',
            });

            return;
        }

        setErrors({});
        setDecisions((all) => ({
            ...all,
            [post.id]: { decision, comment: note.trim() },
        }));
        setNote('');
        setIndex((i) => Math.min(i + 1, posts.length));
    };

    const approveRemaining = () =>
        setDecisions((all) => {
            const next = { ...all };
            posts.forEach((item) => {
                next[item.id] ??= { decision: 'approved', comment: '' };
            });

            return next;
        });

    const send = () => {
        const payload = posts
            .filter((item) => decisions[item.id])
            .map((item) => ({
                post_id: item.id,
                decision: decisions[item.id].decision,
                comment: decisions[item.id].comment || null,
                seen_at: item.seen_at,
            }));

        router.post(
            submitReview.url(campaign.id),
            { decisions: payload },
            {
                onStart: () => setSending(true),
                onFinish: () => setSending(false),
                onError: (bag) => setErrors(bag),
            },
        );
    };

    if (posts.length === 0) {
        return (
            <Page>
                <Head title={`Review ${campaign.name}`} />
                <EmptyState
                    icon={PartyPopper}
                    title="All caught up."
                    description="Nothing in this campaign is waiting on you."
                    action={
                        <Button asChild variant="outline">
                            <Link href={portalCampaign(campaign.id)}>
                                Back to the campaign
                            </Link>
                        </Button>
                    }
                />
            </Page>
        );
    }

    const finished = index >= posts.length || decided === posts.length;

    return (
        <>
            <Head title={`Review ${campaign.name}`} />
            <Page>
                <Link
                    href={portalCampaign(campaign.id)}
                    className="text-muted-foreground hover:text-foreground inline-flex w-fit items-center gap-1.5 text-sm"
                >
                    <ArrowLeft className="size-4" />
                    {campaign.name}
                </Link>
                <PageHeader
                    eyebrow={`Review — ${decided} of ${posts.length} decided`}
                    title={
                        finished ? (
                            <>
                                Ready to <em>send</em>.
                            </>
                        ) : (
                            <>
                                Your <em>review</em>.
                            </>
                        )
                    }
                />

                <div
                    className="bg-muted h-1.5 overflow-hidden rounded-full"
                    role="progressbar"
                    aria-valuemin={0}
                    aria-valuemax={posts.length}
                    aria-valuenow={decided}
                    aria-label="Review progress"
                >
                    <div
                        className="bg-primary h-full rounded-full transition-[width] duration-500"
                        style={{ width: `${(decided / posts.length) * 100}%` }}
                    />
                </div>

                {finished ? (
                    <section className="flex flex-col gap-4">
                        <ul className="border-border bg-card divide-border/70 flex flex-col divide-y rounded-2xl border">
                            {posts.map((item, i) => (
                                <li
                                    key={item.id}
                                    className="flex items-center gap-3 px-4 py-3"
                                >
                                    <span className="min-w-0 flex-1 truncate text-sm">
                                        {item.master_caption ?? 'Untitled post'}
                                    </span>
                                    {decisions[item.id] ? (
                                        <span
                                            className={cn(
                                                'text-xs font-medium',
                                                decisions[item.id].decision ===
                                                    'approved'
                                                    ? 'text-success'
                                                    : 'text-destructive',
                                            )}
                                        >
                                            {decisions[item.id].decision ===
                                            'approved'
                                                ? 'Approve'
                                                : 'Changes'}
                                        </span>
                                    ) : (
                                        <span className="text-muted-foreground text-xs">
                                            Skipped
                                        </span>
                                    )}
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        onClick={() => setIndex(i)}
                                    >
                                        Change
                                    </Button>
                                </li>
                            ))}
                        </ul>
                        <InputError message={Object.values(errors)[0]} />
                        <Button
                            size="lg"
                            className="h-13 text-base"
                            onClick={send}
                            disabled={sending || decided === 0}
                        >
                            <CheckCheck className="size-5" />
                            Send {decided}{' '}
                            {decided === 1 ? 'decision' : 'decisions'} to the
                            studio
                        </Button>
                    </section>
                ) : (
                    post && (
                        <div className="grid grid-cols-1 items-start gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,24rem)]">
                            <section
                                aria-label="Post preview"
                                className="border-border bg-muted/40 flex flex-col items-center gap-3 rounded-2xl border p-4 sm:p-8"
                            >
                                <span className="eyebrow text-muted-foreground">
                                    Post {index + 1} of {posts.length}
                                </span>
                                {target ? (
                                    <SocialPostPreview
                                        target={{
                                            id: target.id,
                                            platform: target.platform ?? '',
                                            handle: target.handle ?? '',
                                            publishedCaption:
                                                target.published_caption,
                                            firstComment: target.first_comment,
                                            format: target.format,
                                        }}
                                        caption={post.master_caption ?? ''}
                                        hashtags={post.hashtags}
                                        assets={post.assets}
                                        musicName={post.music?.name ?? ''}
                                        locationName={post.location?.name ?? ''}
                                        mediaFormat={post.media_format}
                                    />
                                ) : (
                                    <p className="text-muted-foreground py-12 text-sm">
                                        {post.master_caption}
                                    </p>
                                )}
                            </section>

                            <div className="flex flex-col gap-4">
                                {post.review_message && (
                                    <figure className="bg-ink text-ink-foreground relative rounded-2xl p-5">
                                        <span
                                            aria-hidden
                                            className="text-lime font-display absolute -top-3 left-5 text-4xl leading-none font-bold"
                                        >
                                            “
                                        </span>
                                        <blockquote className="text-lg leading-snug font-light whitespace-pre-wrap">
                                            {post.review_message}
                                        </blockquote>
                                        <figcaption className="eyebrow text-ink-foreground/55 mt-2">
                                            A note from the studio
                                        </figcaption>
                                    </figure>
                                )}
                                <ul className="border-border bg-card divide-border/70 divide-y rounded-2xl border text-sm">
                                    {post.targets.map((item) => (
                                        <li
                                            key={item.id}
                                            className="flex items-center justify-between gap-3 px-4 py-2.5"
                                        >
                                            <span>{item.handle}</span>
                                            <span className="text-muted-foreground font-mono text-xs">
                                                {formatSchedule(
                                                    item.scheduled_local_date,
                                                    item.scheduled_local_time,
                                                )}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                                <Textarea
                                    rows={3}
                                    value={note}
                                    onChange={(event) =>
                                        setNote(event.target.value)
                                    }
                                    placeholder="A note for the studio (needed if you ask for changes)"
                                    aria-label="Note for the studio"
                                />
                                <InputError message={errors.note} />
                                {current && (
                                    <p className="text-muted-foreground text-xs">
                                        You chose{' '}
                                        {current.decision === 'approved'
                                            ? 'approve'
                                            : 'changes'}{' '}
                                        for this one. Pick again to change it.
                                    </p>
                                )}
                                <div className="grid gap-2.5">
                                    <Button
                                        size="lg"
                                        className="bg-success text-success-foreground hover:bg-success/90 h-13 text-base"
                                        onClick={() => decide('approved')}
                                    >
                                        <CheckCircle2 className="size-5" />
                                        Approve
                                    </Button>
                                    <Button
                                        size="lg"
                                        variant="outline"
                                        className="border-destructive/35 text-destructive hover:border-destructive/60 hover:bg-destructive/5 hover:text-destructive h-13 text-base"
                                        onClick={() =>
                                            decide('changes_requested')
                                        }
                                    >
                                        <MessageSquareWarning className="size-5" />
                                        Request changes
                                    </Button>
                                </div>
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        disabled={index === 0}
                                        onClick={() =>
                                            setIndex((i) => Math.max(0, i - 1))
                                        }
                                    >
                                        <ChevronLeft />
                                        Back
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        onClick={() => setIndex((i) => i + 1)}
                                    >
                                        Skip
                                        <ArrowRight />
                                    </Button>
                                </div>
                                {posts.length - decided > 1 && (
                                    <Button
                                        variant="outline"
                                        onClick={() => {
                                            approveRemaining();
                                            setIndex(posts.length);
                                        }}
                                    >
                                        <CheckCheck />
                                        Approve all {posts.length -
                                            decided}{' '}
                                        remaining
                                    </Button>
                                )}
                            </div>
                        </div>
                    )
                )}
            </Page>
        </>
    );
}
