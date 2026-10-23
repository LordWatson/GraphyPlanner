import { Link, router } from '@inertiajs/react';
import { Head } from '@inertiajs/react';
import { FileText, Plus, Search } from 'lucide-react';
import { useState } from 'react';
import { EmptyState, Page, PageHeader, Panel } from '@/components/page';
import { platformIcon } from '@/components/platform-icon';
import { Pagination } from '@/components/pagination';
import type { Paginated } from '@/components/pagination';
import { StatusPill } from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { formatSchedule } from '@/lib/dates';
import {
    index as postsIndex,
    create as createPost,
} from '@/routes/business/posts';
import { edit as editPost } from '@/routes/posts';

type Target = {
    id: number;
    platform: string | null;
    handle: string | null;
    scheduled_local_date: string | null;
    scheduled_local_time: string | null;
    scheduled_at_utc: string | null;
};

type PostRow = {
    id: number;
    status: string;
    master_caption: string | null;
    targets: Target[];
};

type Option = { value: string; label: string };

type Filters = {
    search: string | null;
    status: string | null;
    campaign: number | null;
    platform: string | null;
};

type Props = {
    posts: Paginated<PostRow>;
    filters: Filters;
    filterOptions: { statuses: Option[]; collections: Option[] };
    can: { create: boolean };
};

const ALL = '__all__';

export default function BusinessPostsIndex({
    posts,
    filters,
    filterOptions,
    can,
}: Props) {
    const [search, setSearch] = useState(filters.search ?? '');

    const visit = (next: Partial<Filters & { page: number }>) => {
        router.get(
            postsIndex().url,
            {
                search: filters.search ?? undefined,
                status: filters.status ?? undefined,
                campaign: filters.campaign ?? undefined,
                ...next,
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    return (
        <Page>
            <Head title="Posts" />

            <PageHeader
                eyebrow="Your posts"
                title={
                    <>
                        Every <em>post</em>.
                    </>
                }
                description="Drafts, scheduled and published — all in one place."
                actions={
                    can.create && (
                        <Button variant="brand" asChild>
                            <Link href={createPost()} method="post" as="button">
                                <Plus />
                                New post
                            </Link>
                        </Button>
                    )
                }
            />

            <Panel bodyClassName="p-0">
                <div className="border-border/70 flex flex-wrap items-center gap-2 border-b p-4 sm:p-5">
                    <form
                        className="relative min-w-0 flex-1"
                        onSubmit={(event) => {
                            event.preventDefault();
                            visit({ search: search || undefined, page: 1 });
                        }}
                    >
                        <Search className="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2" />
                        <Input
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder="Search captions…"
                            className="pl-9"
                            aria-label="Search posts"
                        />
                    </form>
                    <Select
                        value={filters.status ?? ALL}
                        onValueChange={(value) =>
                            visit({
                                status: value === ALL ? undefined : value,
                                page: 1,
                            })
                        }
                    >
                        <SelectTrigger className="w-[150px]">
                            <SelectValue placeholder="Status" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ALL}>All statuses</SelectItem>
                            {filterOptions.statuses.map((status) => (
                                <SelectItem
                                    key={status.value}
                                    value={status.value}
                                >
                                    {status.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    {filterOptions.collections.length > 0 && (
                        <Select
                            value={
                                filters.campaign
                                    ? String(filters.campaign)
                                    : ALL
                            }
                            onValueChange={(value) =>
                                visit({
                                    campaign:
                                        value === ALL
                                            ? undefined
                                            : Number(value),
                                    page: 1,
                                })
                            }
                        >
                            <SelectTrigger className="w-[170px]">
                                <SelectValue placeholder="Collection" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={ALL}>
                                    All collections
                                </SelectItem>
                                {filterOptions.collections.map((collection) => (
                                    <SelectItem
                                        key={collection.value}
                                        value={collection.value}
                                    >
                                        {collection.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    )}
                </div>

                {posts.data.length === 0 ? (
                    <div className="p-6">
                        <EmptyState
                            icon={FileText}
                            title="No posts yet."
                            description="Start your first post — a caption, a photo, a time. We'll handle the rest."
                            action={
                                can.create && (
                                    <Button variant="brand" asChild>
                                        <Link
                                            href={createPost()}
                                            method="post"
                                            as="button"
                                        >
                                            <Plus />
                                            New post
                                        </Link>
                                    </Button>
                                )
                            }
                        />
                    </div>
                ) : (
                    <ul className="divide-border/70 divide-y">
                        {posts.data.map((post) => (
                            <li key={post.id}>
                                <Link
                                    href={editPost(post.id)}
                                    className="hover:bg-muted/50 flex items-center gap-4 px-5 py-4 transition-colors sm:px-6"
                                >
                                    <StatusPill
                                        kind="post"
                                        value={post.status}
                                        className="shrink-0"
                                    />
                                    <span className="min-w-0 flex-1 truncate text-sm font-medium">
                                        {post.master_caption ?? 'Untitled post'}
                                    </span>
                                    <PlatformDots targets={post.targets} />
                                    <span className="eyebrow text-muted-foreground hidden shrink-0 text-right normal-case sm:block">
                                        {nextSchedule(post.targets)}
                                    </span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </Panel>

            <Pagination
                page={posts}
                label="posts"
                onPageChange={(page) => visit({ page })}
            />
        </Page>
    );
}

function PlatformDots({ targets }: { targets: Target[] }) {
    const platforms = [
        ...new Set(targets.map((target) => target.platform).filter(Boolean)),
    ] as string[];

    if (platforms.length === 0) {
        return null;
    }

    return (
        <span className="hidden shrink-0 items-center gap-1.5 sm:flex">
            {platforms.map((platform) => {
                const Icon = platformIcon(platform);

                return (
                    <Icon
                        key={platform}
                        className="text-muted-foreground size-4"
                    />
                );
            })}
        </span>
    );
}

function nextSchedule(targets: Target[]): string {
    const scheduled = targets
        .filter((target) => target.scheduled_at_utc)
        .sort((a, b) =>
            (a.scheduled_at_utc ?? '').localeCompare(b.scheduled_at_utc ?? ''),
        );

    const first = scheduled[0];

    return first
        ? formatSchedule(first.scheduled_local_date, first.scheduled_local_time)
        : 'Not scheduled';
}

BusinessPostsIndex.layout = {
    breadcrumbs: [{ title: 'Posts', href: postsIndex() }],
};
