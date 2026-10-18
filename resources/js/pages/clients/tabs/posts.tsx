import { Form, router } from '@inertiajs/react';
import { Pencil, Send, Share2 } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { StatusPill } from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatSchedule } from '@/lib/dates';
import { index } from '@/routes/clients';
import { store as storePost } from '@/routes/clients/posts';
import { edit as editPost } from '@/routes/posts';
import type { ClientPageProps } from '@/components/clients/client-layout';
import {
    ClientLayout,
    TabHeading,
    TabPanel,
    platformIcon,
} from '@/components/clients/client-layout';
import type { Paginated } from '@/components/pagination';
import { Pagination } from '@/components/pagination';

type PostTargetData = {
    id: number;
    social_account_id: number;
    platform: string | null;
    handle: string | null;
    scheduled_local_date: string | null;
    scheduled_local_time: string | null;
    scheduled_at_utc: string | null;
};

type PostData = {
    id: number;
    campaign_id: number | null;
    campaign_name: string | null;
    status: string;
    approval_mode: string;
    master_caption: string | null;
    hashtags: string[] | null;
    targets: PostTargetData[];
    created_at: string | null;
    can: { update: boolean; delete: boolean };
};

type TargetAccountData = {
    id: number;
    platform: string;
    handle: string;
    timezone: string;
};

/**
 * Step I-2.4 — Posts tab (`clients.posts.index`), paginated newest first.
 */
export default function ClientPostsTab({
    posts: postPage,
    targetAccounts,
    ...page
}: ClientPageProps & {
    posts: Paginated<PostData>;
    targetAccounts: TargetAccountData[];
}) {
    const { client, can } = page;
    const posts = postPage.data;
    const [selectedTargetAccounts, setSelectedTargetAccounts] = useState<
        number[]
    >([]);

    const toggleTargetAccount = (id: number) => {
        setSelectedTargetAccounts((current) =>
            current.includes(id)
                ? current.filter((accountId) => accountId !== id)
                : [...current, id],
        );
    };

    return (
        <ClientLayout {...page}>
            <TabPanel>
                <TabHeading
                    icon={Send}
                    title="Posts"
                    description="Scheduled content across social accounts"
                />

                <>
                    <>
                        {can.createPost && (
                            <Form
                                {...storePost.form(client.id)}
                                resetOnSuccess
                                onSuccess={() => setSelectedTargetAccounts([])}
                                className="border-border bg-card/50 grid grid-cols-2 gap-3 rounded-2xl border border-dashed p-4 shadow-sm sm:grid-cols-4"
                            >
                                {({ processing, errors }) => (
                                    <>
                                        <div className="col-span-full grid gap-1">
                                            <Label htmlFor="master_caption">
                                                Caption
                                            </Label>
                                            <textarea
                                                id="master_caption"
                                                name="master_caption"
                                                rows={3}
                                                className="border-input dark:bg-input/30 focus-visible:border-ring focus-visible:ring-ring/50 flex w-full rounded-md border bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:ring-[3px]"
                                            />
                                            <InputError
                                                message={errors.master_caption}
                                            />
                                        </div>
                                        <div className="col-span-full grid gap-1">
                                            <Label>Target accounts</Label>
                                            <div className="flex flex-wrap gap-2">
                                                {targetAccounts.length ===
                                                    0 && (
                                                    <p className="text-muted-foreground text-sm">
                                                        Add a social account
                                                        before creating posts.
                                                    </p>
                                                )}
                                                {targetAccounts.map(
                                                    (account) => {
                                                        const AccountIcon =
                                                            platformIcon[
                                                                account.platform
                                                            ] ?? Share2;
                                                        const selected =
                                                            selectedTargetAccounts.includes(
                                                                account.id,
                                                            );
                                                        return (
                                                            <button
                                                                key={account.id}
                                                                type="button"
                                                                onClick={() =>
                                                                    toggleTargetAccount(
                                                                        account.id,
                                                                    )
                                                                }
                                                                className={`inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs transition-colors ${
                                                                    selected
                                                                        ? 'border-primary bg-primary/10 text-primary'
                                                                        : 'border-border bg-muted/50 text-foreground'
                                                                }`}
                                                            >
                                                                <AccountIcon className="size-3.5" />
                                                                {account.handle}{' '}
                                                                ·{' '}
                                                                {
                                                                    account.timezone
                                                                }
                                                            </button>
                                                        );
                                                    },
                                                )}
                                            </div>
                                            {selectedTargetAccounts.map(
                                                (accountId, i) => (
                                                    <input
                                                        key={accountId}
                                                        type="hidden"
                                                        name={`targets[${i}][social_account_id]`}
                                                        value={accountId}
                                                    />
                                                ),
                                            )}
                                        </div>
                                        <div className="grid gap-1">
                                            <Label htmlFor="scheduled_local_date">
                                                Scheduled date
                                            </Label>
                                            <Input
                                                id="scheduled_local_date"
                                                type="date"
                                                onChange={(e) => {
                                                    document
                                                        .querySelectorAll<HTMLInputElement>(
                                                            'input[name$="[scheduled_local_date]"]',
                                                        )
                                                        .forEach(
                                                            (input) =>
                                                                (input.value =
                                                                    e.target.value),
                                                        );
                                                }}
                                            />
                                            {selectedTargetAccounts.map(
                                                (accountId, i) => (
                                                    <input
                                                        key={accountId}
                                                        type="hidden"
                                                        name={`targets[${i}][scheduled_local_date]`}
                                                    />
                                                ),
                                            )}
                                        </div>
                                        <div className="grid gap-1">
                                            <Label htmlFor="scheduled_local_time">
                                                Scheduled time (local)
                                            </Label>
                                            <Input
                                                id="scheduled_local_time"
                                                type="time"
                                                onChange={(e) => {
                                                    document
                                                        .querySelectorAll<HTMLInputElement>(
                                                            'input[name$="[scheduled_local_time]"]',
                                                        )
                                                        .forEach(
                                                            (input) =>
                                                                (input.value =
                                                                    e.target.value),
                                                        );
                                                }}
                                            />
                                            {selectedTargetAccounts.map(
                                                (accountId, i) => (
                                                    <input
                                                        key={accountId}
                                                        type="hidden"
                                                        name={`targets[${i}][scheduled_local_time]`}
                                                    />
                                                ),
                                            )}
                                        </div>
                                        <InputError
                                            message={
                                                errors[
                                                    'targets.0.social_account_id'
                                                ]
                                            }
                                        />
                                        <div className="col-span-full">
                                            <Button
                                                type="submit"
                                                size="sm"
                                                disabled={
                                                    processing ||
                                                    selectedTargetAccounts.length ===
                                                        0
                                                }
                                            >
                                                Create post
                                            </Button>
                                        </div>
                                    </>
                                )}
                            </Form>
                        )}

                        {posts.length === 0 ? (
                            <p className="text-muted-foreground text-sm">
                                No posts yet.
                            </p>
                        ) : (
                            <div className="grid gap-3">
                                {posts.map((post) => (
                                    <div
                                        key={post.id}
                                        role="button"
                                        tabIndex={0}
                                        onClick={() =>
                                            router.visit(editPost(post.id).url)
                                        }
                                        onKeyDown={(e) => {
                                            if (
                                                e.key === 'Enter' ||
                                                e.key === ' '
                                            ) {
                                                e.preventDefault();
                                                router.visit(
                                                    editPost(post.id).url,
                                                );
                                            }
                                        }}
                                        className="border-border bg-card hover:border-primary/30 hover:bg-muted/40 flex cursor-pointer flex-col gap-2 rounded-xl border p-3 shadow-sm transition-colors"
                                    >
                                        <div className="flex flex-wrap items-center justify-between gap-2">
                                            <div className="flex items-center gap-2">
                                                <StatusPill
                                                    kind="post"
                                                    value={post.status}
                                                />
                                                {post.campaign_name && (
                                                    <span className="text-muted-foreground text-xs">
                                                        {post.campaign_name}
                                                    </span>
                                                )}
                                            </div>
                                            <span className="text-muted-foreground inline-flex items-center gap-1.5 text-xs">
                                                <Pencil className="size-3.5" />
                                                Open editor
                                            </span>
                                        </div>
                                        {post.master_caption && (
                                            <p className="text-sm">
                                                {post.master_caption}
                                            </p>
                                        )}
                                        {post.targets.length > 0 && (
                                            <div className="flex flex-wrap gap-2">
                                                {post.targets.map((target) => {
                                                    const AccountIcon =
                                                        platformIcon[
                                                            target.platform ??
                                                                ''
                                                        ] ?? Share2;
                                                    return (
                                                        <span
                                                            key={target.id}
                                                            className="border-border bg-background text-foreground inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs"
                                                        >
                                                            <AccountIcon className="text-primary size-3.5" />
                                                            {target.handle} ·{' '}
                                                            {formatSchedule(
                                                                target.scheduled_local_date,
                                                                target.scheduled_local_time,
                                                                '—',
                                                            )}
                                                        </span>
                                                    );
                                                })}
                                            </div>
                                        )}
                                    </div>
                                ))}
                            </div>
                        )}
                    </>
                </>
            </TabPanel>
            <Pagination
                page={postPage}
                label="posts"
                onPageChange={(page) =>
                    router.reload({ data: { page }, only: ['posts'] })
                }
            />
        </ClientLayout>
    );
}

ClientPostsTab.layout = {
    breadcrumbs: [{ title: 'Clients', href: index() }],
};
