import { Form, Head, Link } from '@inertiajs/react';
import { ArrowLeft, Pencil, Trash2 } from 'lucide-react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { index as indexClients, show as showClient } from '@/routes/clients';
import {
    edit as editCampaign,
    destroy as destroyCampaign,
} from '@/routes/campaigns';
import { edit as editPost } from '@/routes/posts';
import { PageHeader } from '@/components/page';
import { StatusPill } from '@/components/status-pill';

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
    targets: {
        platform: string | null;
        handle: string | null;
        scheduled_local_date: string | null;
    }[];
    created_at: string | null;
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
            <div className="mx-auto flex w-full max-w-4xl flex-col gap-8 px-4 py-6 md:px-8 md:py-9">
                <div className="flex flex-col gap-4">
                    <Link
                        href={showClient(client.id)}
                        className="text-muted-foreground hover:text-foreground inline-flex items-center gap-1.5 text-sm"
                    >
                        <ArrowLeft className="size-4" />
                        Back to {client.name}
                    </Link>
                </div>

                <PageHeader
                    eyebrow={`Campaign — ${client.name}`}
                    title={campaign.name}
                    meta={
                        <StatusPill
                            kind="campaign"
                            value={campaign.status}
                            label={campaign.status_label}
                        />
                    }
                />

                <div className="border-border bg-card grid gap-4 rounded-2xl border p-4 shadow-sm">
                    <Heading title="Campaign details" />
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div className="grid gap-1">
                            <span className="text-muted-foreground text-xs">
                                Start date
                            </span>
                            <span className="text-sm font-medium">
                                {campaign.start_date ?? '—'}
                            </span>
                        </div>
                        <div className="grid gap-1">
                            <span className="text-muted-foreground text-xs">
                                End date
                            </span>
                            <span className="text-sm font-medium">
                                {campaign.end_date ?? '—'}
                            </span>
                        </div>
                        <div className="col-span-full grid gap-1">
                            <span className="text-muted-foreground text-xs">
                                Goal
                            </span>
                            <span className="text-sm font-medium whitespace-pre-line">
                                {campaign.goal ?? '—'}
                            </span>
                        </div>
                        <div className="col-span-full grid gap-1">
                            <span className="text-muted-foreground text-xs">
                                Notes
                            </span>
                            <span className="text-sm font-medium whitespace-pre-line">
                                {campaign.notes ?? '—'}
                            </span>
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
                                    <Button
                                        type="submit"
                                        size="sm"
                                        variant="outline"
                                        disabled={processing}
                                    >
                                        <Trash2 />
                                        Delete
                                    </Button>
                                )}
                            </Form>
                        )}
                    </div>
                </div>

                <div className="border-border bg-card grid gap-3 rounded-2xl border p-4 shadow-sm">
                    <Heading
                        title="Posts"
                        description="Posts attached to this campaign"
                    />
                    {posts.length === 0 ? (
                        <p className="text-muted-foreground text-sm">
                            No posts attached to this campaign yet.
                        </p>
                    ) : (
                        <div className="flex flex-col gap-2">
                            {posts.map((post) => (
                                <Link
                                    key={post.id}
                                    href={editPost(post.id)}
                                    className="border-border bg-muted/20 hover:border-primary/30 hover:bg-muted/40 flex items-center justify-between gap-3 rounded-xl border px-4 py-3 text-sm transition-colors"
                                >
                                    <div className="flex flex-col">
                                        <span className="font-medium">
                                            {post.master_caption ??
                                                'Untitled post'}
                                        </span>
                                        <span className="text-muted-foreground truncate text-xs">
                                            {post.targets
                                                .map(
                                                    (target) =>
                                                        target.handle ??
                                                        target.platform,
                                                )
                                                .filter(Boolean)
                                                .join(', ') ||
                                                'No target accounts'}
                                        </span>
                                    </div>
                                    <StatusPill
                                        kind="post"
                                        value={post.status}
                                        label={post.status_label}
                                        className="whitespace-nowrap"
                                    />
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
