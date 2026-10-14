import { Form, Head, Link } from '@inertiajs/react';
import { ArrowLeft, Clapperboard, Pencil, Trash2 } from 'lucide-react';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { index as indexClients, show as showClient } from '@/routes/clients';
import { edit as editCampaign, destroy as destroyCampaign } from '@/routes/campaigns';
import { edit as editPost } from '@/routes/posts';

type CampaignData = {
    id: number;
    name: string;
    status: string;
    status_label: string;
    start_date: string | null;
    end_date: string | null;
    goal: string | null;
    notes: string | null;
};

type PostRow = {
    id: number;
    status: string;
    status_label: string;
    master_caption: string | null;
    targets: { platform: string | null; handle: string | null; scheduled_local_date: string | null }[];
    created_at: string | null;
};

const campaignStatusVariant: Record<string, 'success' | 'warning' | 'secondary' | 'outline'> = {
    planning: 'secondary',
    active: 'success',
    completed: 'outline',
    archived: 'outline',
};

const postStatusVariant: Record<string, 'success' | 'warning' | 'secondary' | 'outline' | 'destructive'> = {
    waiting_client: 'warning',
    changes_requested: 'destructive',
    approved: 'success',
    published: 'success',
    failed: 'destructive',
};

export default function CampaignShow({
    campaign,
    client,
    posts,
    can,
}: {
    campaign: CampaignData;
    client: { id: number; name: string };
    posts: PostRow[];
    can: { update: boolean; delete: boolean };
}) {
    return (
        <>
            <Head title={`${campaign.name} — ${client.name}`} />
            <div className="mx-auto flex w-full max-w-4xl flex-col gap-6 p-4">
                <div className="flex flex-col gap-4">
                    <Link
                        href={showClient(client.id)}
                        className="inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground"
                    >
                        <ArrowLeft className="size-4" />
                        Back to {client.name}
                    </Link>
                </div>

                <div className="bg-noise relative flex flex-wrap items-center justify-between gap-3 overflow-hidden rounded-2xl bg-gradient-brand-animated p-6 text-primary-foreground shadow-agency sm:p-8">
                    <span
                        aria-hidden
                        className="pointer-events-none absolute -top-16 -right-16 size-56 rounded-full bg-white/10 blur-2xl"
                    />
                    <span
                        aria-hidden
                        className="pointer-events-none absolute inset-0 bg-[radial-gradient(white_1px,transparent_1px)] bg-[size:22px_22px] opacity-[0.08]"
                    />
                    <div className="relative flex items-center gap-3">
                        <span className="flex size-11 shrink-0 items-center justify-center rounded-xl bg-white/15 text-primary-foreground">
                            <Clapperboard className="size-5" />
                        </span>
                        <div className="flex flex-col gap-1">
                            <span className="inline-flex w-fit items-center gap-2 rounded-full bg-white/15 px-3 py-1 text-[10px] font-medium tracking-widest uppercase">
                                Campaign
                            </span>
                            <h1 className="font-serif text-2xl font-semibold tracking-tight italic sm:text-3xl">{campaign.name}</h1>
                            <p className="text-sm text-primary-foreground/80">Client: {client.name}</p>
                        </div>
                    </div>
                    <Badge variant={campaignStatusVariant[campaign.status] ?? 'outline'} className="relative bg-white/90">
                        {campaign.status_label}
                    </Badge>
                </div>

                <div className="grid gap-4 rounded-2xl border border-border bg-card p-4 shadow-sm">
                    <Heading title="Campaign details" />
                    <div className="grid gap-3 sm:grid-cols-2">
                        <div className="grid gap-1">
                            <span className="text-xs text-muted-foreground">Start date</span>
                            <span className="text-sm font-medium">{campaign.start_date ?? '—'}</span>
                        </div>
                        <div className="grid gap-1">
                            <span className="text-xs text-muted-foreground">End date</span>
                            <span className="text-sm font-medium">{campaign.end_date ?? '—'}</span>
                        </div>
                        <div className="col-span-full grid gap-1">
                            <span className="text-xs text-muted-foreground">Goal</span>
                            <span className="text-sm font-medium whitespace-pre-line">{campaign.goal ?? '—'}</span>
                        </div>
                        <div className="col-span-full grid gap-1">
                            <span className="text-xs text-muted-foreground">Notes</span>
                            <span className="text-sm font-medium whitespace-pre-line">{campaign.notes ?? '—'}</span>
                        </div>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        {can.update && (
                            <Button asChild size="sm" variant="outline">
                                <Link href={editCampaign(campaign.id)}>
                                    <Pencil />
                                    Edit
                                </Link>
                            </Button>
                        )}
                        {can.delete && (
                            <Form {...destroyCampaign.form(campaign.id)}>
                                {({ processing }) => (
                                    <Button type="submit" size="sm" variant="outline" disabled={processing}>
                                        <Trash2 />
                                        Delete
                                    </Button>
                                )}
                            </Form>
                        )}
                    </div>
                </div>

                <div className="grid gap-3 rounded-2xl border border-border bg-card p-4 shadow-sm">
                    <Heading title="Posts" description="Posts attached to this campaign" />
                    {posts.length === 0 ? (
                        <p className="text-sm text-muted-foreground">No posts attached to this campaign yet.</p>
                    ) : (
                        <div className="flex flex-col gap-2">
                            {posts.map((post) => (
                                <Link
                                    key={post.id}
                                    href={editPost(post.id)}
                                    className="flex items-center justify-between gap-3 rounded-xl border border-border bg-muted/20 px-4 py-3 text-sm transition-all hover:-translate-y-0.5 hover:border-primary/30 hover:bg-muted/40"
                                >
                                    <div className="flex flex-col">
                                        <span className="font-medium">{post.master_caption ?? 'Untitled post'}</span>
                                        <span className="truncate text-xs text-muted-foreground">
                                            {post.targets.map((target) => target.handle ?? target.platform).filter(Boolean).join(', ') ||
                                                'No target accounts'}
                                        </span>
                                    </div>
                                    <Badge variant={postStatusVariant[post.status] ?? 'outline'} className="whitespace-nowrap text-[10px]">
                                        {post.status_label}
                                    </Badge>
                                </Link>
                            ))}
                        </div>
                    )}
                </div>
            </div>
        </>
    );
}

CampaignShow.layout = {
    breadcrumbs: [{ title: 'Clients', href: indexClients() }],
};
