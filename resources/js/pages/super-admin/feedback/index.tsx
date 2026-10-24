import { Head, Link, router } from '@inertiajs/react';
import { Building2, CheckCircle2 } from 'lucide-react';
import { EmptyState, Page, PageHeader, Panel } from '@/components/page';
import { Pagination, type Paginated } from '@/components/pagination';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { formatDateTime } from '@/lib/dates';
import { index as feedbackIndex } from '@/routes/super-admin/feedback';
import { show as showOrganization } from '@/routes/super-admin/organizations';

type FeedbackRow = {
    id: number;
    message: string;
    page: string | null;
    created_at: string | null;
    organization: { id: number; name: string; slug: string } | null;
    user: { id: number; name: string; email: string } | null;
};

type Props = {
    feedback: Paginated<FeedbackRow>;
    filters: { org: number | null };
    organizations: { id: number; name: string }[];
};

const ALL = 'all';

/**
 * Step M-8.2 — the platform feedback inbox: every in-app note from partner staff, newest first,
 * optionally narrowed to one organization. Reached from the Partners overview and from the super
 * admin's notification bell when a note comes in.
 */
export default function FeedbackIndex({
    feedback,
    filters,
    organizations,
}: Props) {
    const onOrgChange = (value: string) => {
        router.get(
            feedbackIndex.url({
                query: value === ALL ? {} : { org: value },
            }),
            undefined,
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    const onPageChange = (page: number) => {
        router.get(
            feedbackIndex.url({
                query: {
                    ...(filters.org ? { org: filters.org } : {}),
                    page,
                },
            }),
            undefined,
            { preserveState: true, preserveScroll: true },
        );
    };

    return (
        <>
            <Head title="Feedback inbox" />
            <Page>
                <PageHeader
                    eyebrow={`${feedback.total} ${feedback.total === 1 ? 'note' : 'notes'}`}
                    title={
                        <>
                            Feedback <em>inbox</em>.
                        </>
                    }
                    description="Everything partners have sent from the in-app panel. The newest arrive at the top."
                    actions={
                        <Select
                            value={filters.org ? String(filters.org) : ALL}
                            onValueChange={onOrgChange}
                        >
                            <SelectTrigger
                                className="w-full sm:w-64"
                                aria-label="Filter by organization"
                            >
                                <SelectValue placeholder="All organizations" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={ALL}>
                                    All organizations
                                </SelectItem>
                                {organizations.map((org) => (
                                    <SelectItem
                                        key={org.id}
                                        value={String(org.id)}
                                    >
                                        {org.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    }
                />

                {feedback.data.length === 0 ? (
                    <EmptyState
                        icon={CheckCircle2}
                        title="Nothing here yet."
                        description="When a partner sends feedback from the in-app panel, it lands here and pings your bell."
                    />
                ) : (
                    <Panel bodyClassName="p-0 sm:p-0">
                        <ul className="divide-border/70 divide-y">
                            {feedback.data.map((item) => (
                                <li
                                    key={item.id}
                                    className="flex flex-col gap-2 px-5 py-5 sm:px-6"
                                >
                                    <p className="text-[15px] leading-relaxed text-pretty">
                                        {item.message}
                                    </p>
                                    <div className="text-muted-foreground flex flex-wrap items-center gap-x-2 gap-y-1 text-xs">
                                        {item.organization ? (
                                            <Link
                                                href={showOrganization(
                                                    item.organization.id,
                                                )}
                                                className="text-foreground inline-flex items-center gap-1 font-medium hover:underline"
                                            >
                                                <Building2 className="size-3.5" />
                                                {item.organization.name}
                                            </Link>
                                        ) : (
                                            <span className="text-foreground font-medium">
                                                Unknown workspace
                                            </span>
                                        )}
                                        {item.user && (
                                            <>
                                                <span aria-hidden>·</span>
                                                <span>
                                                    {item.user.name} (
                                                    {item.user.email})
                                                </span>
                                            </>
                                        )}
                                        {item.page && (
                                            <>
                                                <span aria-hidden>·</span>
                                                <span className="font-mono">
                                                    {item.page}
                                                </span>
                                            </>
                                        )}
                                        <span aria-hidden>·</span>
                                        <span className="font-mono">
                                            {formatDateTime(item.created_at)}
                                        </span>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    </Panel>
                )}

                <Pagination
                    page={feedback}
                    onPageChange={onPageChange}
                    label="notes"
                />
            </Page>
        </>
    );
}

FeedbackIndex.layout = {
    breadcrumbs: [{ title: 'Partners', href: feedbackIndex() }],
};
