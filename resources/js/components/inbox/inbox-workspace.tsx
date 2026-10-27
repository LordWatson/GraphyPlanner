import { Head, router } from '@inertiajs/react';
import {
    ArrowLeft,
    CheckCircle2,
    Clock,
    ExternalLink,
    MessageSquareHeart,
    RefreshCw,
    Search,
    Send,
    Sparkles,
    UserRound,
} from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { toast } from 'sonner';
import { ChapterHero, EmptyState, HeroStat, Page } from '@/components/page';
import { platformIcon } from '@/components/platform-icon';
import { StatusPill } from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { Pagination, type Paginated } from '@/components/pagination';
import { formatDateTime } from '@/lib/dates';
import { http } from '@/lib/http';
import { cn } from '@/lib/utils';
import { index as inboxIndex, refresh as refreshRoute } from '@/routes/inbox';
import {
    draft as draftRoute,
    reply as replyRoute,
    show as showRoute,
    update as updateRoute,
} from '@/routes/inbox/conversations';

type Option = { value: string; label: string };
type NumberOption = { value: number; label: string };

/**
 * A Wayfinder-style route builder — `fn().url` for the list/refresh, `fn({ conversation }).url`
 * for the per-conversation endpoints. The agency and business inbox shells differ only in which
 * route set (and a few copy/visibility flags) they pass to this one component — the behaviour is
 * never forked (AGENTS.md principle 1).
 */
export type InboxRoutes = {
    index: () => { url: string };
    refresh: () => { url: string };
    show: (args: { conversation: number }) => { url: string };
    update: (args: { conversation: number }) => { url: string };
    reply: (args: { conversation: number }) => { url: string };
    draft: (args: { conversation: number }) => { url: string };
};

const agencyRoutes: InboxRoutes = {
    index: inboxIndex,
    refresh: refreshRoute,
    show: showRoute,
    update: updateRoute,
    reply: replyRoute,
    draft: draftRoute,
};

type Author = {
    name: string | null;
    handle: string | null;
    avatar_url: string | null;
};

type ConversationRow = {
    id: number;
    client: { id: number | null; name: string | null };
    platform: string;
    platform_label: string;
    author: Author;
    sentiment: string;
    status: string;
    assignee: { id: number; name: string } | null;
    is_reply_needed: boolean;
    snippet: string | null;
    last_message_at: string | null;
    last_inbound_at: string | null;
    snoozed_until: string | null;
};

export type Message = {
    id: number;
    direction: 'inbound' | 'outbound';
    author_name: string | null;
    author_handle: string | null;
    body: string;
    sentiment: string | null;
    posted_at: string | null;
    delivery_status: string | null;
    delivery_error: string | null;
    sent_by: string | null;
};

export type ConversationDetail = ConversationRow & {
    permalink: string | null;
    resolved_at: string | null;
    first_response_at: string | null;
    post: {
        id: number;
        caption: string | null;
        thumbnail_url: string | null;
    } | null;
    messages: Message[];
    can: { reply: boolean; act: boolean };
};

export type SavedReply = {
    id: number;
    title: string;
    body: string;
    shortcut: string | null;
    client_id: number | null;
};

type Filters = {
    status: string | null;
    sentiment: string | null;
    client_id: number | null;
    platform: string | null;
    assignee: string | null;
    reply_needed: boolean;
    search: string | null;
};

export type InboxFilterOptions = {
    statuses: Option[];
    sentiments: Option[];
    platforms: Option[];
    clients: NumberOption[];
    staff: NumberOption[];
};

export type InboxWorkspaceProps = {
    filters: Filters;
    conversations: Paginated<ConversationRow>;
    replyNeededCount: number;
    filterOptions: InboxFilterOptions;
    savedReplies: SavedReply[];
    aiEnabled: boolean;
    can: { act: boolean };
    /** The route set to drive (agency `/inbox` or business `/inbox`). */
    routes?: InboxRoutes;
    /** Show the client filter + per-row client name (an agency manages many brands). */
    multiClient?: boolean;
    /** Show assignment UI (an assignee filter + the thread assign dropdown). */
    showAssignment?: boolean;
    /** The hero strap-line (brand-aware copy). */
    description?: string;
};

const ALL = '__all';

function initials(name: string | null): string {
    if (!name) return '?';
    return name
        .split(' ')
        .map((p) => p.charAt(0))
        .join('')
        .slice(0, 2)
        .toUpperCase();
}

export function InboxWorkspace(props: InboxWorkspaceProps) {
    const {
        filters,
        conversations,
        replyNeededCount,
        filterOptions,
        savedReplies,
        aiEnabled,
        can,
        routes = agencyRoutes,
        multiClient = true,
        showAssignment = true,
        description = 'Every comment across every client, triaged and ready for an on-brand reply a human always sends.',
    } = props;

    const [selectedId, setSelectedId] = useState<number | null>(null);
    const [detail, setDetail] = useState<ConversationDetail | null>(null);
    const [loadingDetail, setLoadingDetail] = useState(false);
    const [search, setSearch] = useState(filters.search ?? '');

    const rows = conversations.data;

    const openConversation = useCallback(
        async (id: number) => {
            setSelectedId(id);
            setDetail(null);
            setLoadingDetail(true);
            const result = await http.get<{ conversation: ConversationDetail }>(
                routes.show({ conversation: id }).url,
                { toastOnError: true },
            );
            setLoadingDetail(false);
            if (result.ok) {
                setDetail(result.data.conversation);
            }
        },
        [routes],
    );

    // When the list changes (filter/refresh), keep the open thread if it's still visible.
    useEffect(() => {
        if (selectedId !== null && !rows.some((r) => r.id === selectedId)) {
            setSelectedId(null);
            setDetail(null);
        }
    }, [rows, selectedId]);

    const applyFilters = useCallback(
        (next: Partial<Record<string, string | number | boolean | null>>) => {
            const params: Record<string, string | number> = {};
            const merged = { ...currentParams(filters, multiClient), ...next };
            Object.entries(merged).forEach(([key, value]) => {
                if (value !== null && value !== '' && value !== false) {
                    params[key] =
                        value === true ? 1 : (value as string | number);
                }
            });
            router.get(routes.index().url, params, {
                preserveState: true,
                preserveScroll: true,
                replace: true,
            });
        },
        [filters, multiClient, routes],
    );

    const searchTimer = useRef<ReturnType<typeof setTimeout>>(undefined);
    const onSearch = (value: string) => {
        setSearch(value);
        clearTimeout(searchTimer.current);
        searchTimer.current = setTimeout(
            () => applyFilters({ search: value || null }),
            350,
        );
    };

    const refresh = () => {
        router.post(
            routes.refresh().url,
            {},
            {
                preserveScroll: true,
                onSuccess: () => toast.success('Checking for new comments…'),
            },
        );
    };

    const startReplying = () => {
        const first = rows.find((r) => r.is_reply_needed) ?? rows[0];
        if (first) void openConversation(first.id);
    };

    const onDetailChanged = (next: ConversationDetail) => {
        setDetail(next);
        // Keep the list row in sync (status/sentiment/reply-needed) without a full reload.
        router.reload({ only: ['conversations', 'replyNeededCount'] });
    };

    return (
        <Page>
            <Head title="Inbox" />

            <ChapterHero
                eyebrow={`${String(replyNeededCount).padStart(2, '0')} awaiting a reply`}
                title={
                    <>
                        Your social <em>inbox</em>.
                    </>
                }
                description={description}
                actions={
                    <>
                        {replyNeededCount > 0 && (
                            <Button
                                variant="spark"
                                size="lg"
                                onClick={startReplying}
                            >
                                <MessageSquareHeart />
                                Start replying
                            </Button>
                        )}
                        <Button variant="on-ink" size="lg" onClick={refresh}>
                            <RefreshCw />
                            Refresh
                        </Button>
                    </>
                }
                aside={
                    <HeroStat
                        icon={MessageSquareHeart}
                        eyebrow="Needs a reply"
                        value={String(replyNeededCount).padStart(2, '0')}
                        caption="Open questions and complaints"
                    />
                }
            />

            <FilterBar
                filters={filters}
                options={filterOptions}
                search={search}
                onSearch={onSearch}
                applyFilters={applyFilters}
                multiClient={multiClient}
                showAssignment={showAssignment}
            />

            <div className="grid gap-5 lg:grid-cols-[1fr_1.5fr]">
                <div
                    className={cn(
                        'animate-rise',
                        selectedId !== null && 'hidden lg:block',
                    )}
                >
                    <ConversationList
                        rows={rows}
                        selectedId={selectedId}
                        onSelect={openConversation}
                        multiClient={multiClient}
                    />
                    <Pagination
                        page={conversations}
                        label="conversations"
                        className="mt-4"
                        onPageChange={(page) =>
                            router.get(
                                routes.index().url,
                                cleanParams(filters, page, multiClient),
                                {
                                    preserveState: true,
                                    preserveScroll: true,
                                    replace: true,
                                },
                            )
                        }
                    />
                </div>

                <div
                    className={cn(
                        'animate-rise [animation-delay:80ms]',
                        selectedId === null && 'hidden lg:block',
                    )}
                >
                    <ThreadPane
                        detail={detail}
                        loading={loadingDetail}
                        canAct={can.act}
                        aiEnabled={aiEnabled}
                        savedReplies={savedReplies}
                        staff={filterOptions.staff}
                        multiClient={multiClient}
                        showAssignment={showAssignment}
                        routes={routes}
                        onBack={() => {
                            setSelectedId(null);
                            setDetail(null);
                        }}
                        onChanged={onDetailChanged}
                    />
                </div>
            </div>
        </Page>
    );
}

function cleanParams(
    filters: Filters,
    page: number,
    multiClient: boolean,
): Record<string, string | number> {
    const params: Record<string, string | number> = { page };
    Object.entries(currentParams(filters, multiClient)).forEach(
        ([key, value]) => {
            if (value !== null && value !== '' && value !== false) {
                params[key] = value === true ? 1 : (value as string | number);
            }
        },
    );
    return params;
}

function currentParams(
    filters: Filters,
    multiClient: boolean,
): Record<string, string | number | boolean | null> {
    return {
        status: filters.status,
        sentiment: filters.sentiment,
        // The business shell has one implicit brand, so it never carries a client param.
        ...(multiClient ? { client: filters.client_id } : {}),
        platform: filters.platform,
        assignee: filters.assignee,
        reply_needed: filters.reply_needed,
        search: filters.search,
    };
}

function FilterBar({
    filters,
    options,
    search,
    onSearch,
    applyFilters,
    multiClient,
    showAssignment,
}: {
    filters: Filters;
    options: InboxFilterOptions;
    search: string;
    onSearch: (value: string) => void;
    applyFilters: (
        next: Partial<Record<string, string | number | boolean | null>>,
    ) => void;
    multiClient: boolean;
    showAssignment: boolean;
}) {
    return (
        <div className="animate-rise border-border flex flex-wrap items-center gap-2 border-y py-3">
            <div className="relative min-w-[180px] flex-1">
                <Search className="text-muted-foreground absolute top-1/2 left-3 size-4 -translate-y-1/2" />
                <Input
                    value={search}
                    onChange={(e) => onSearch(e.target.value)}
                    placeholder="Search comments…"
                    className="pl-9"
                />
            </div>

            {multiClient && (
                <FilterSelect
                    label="Client"
                    value={filters.client_id ? String(filters.client_id) : ALL}
                    options={options.clients.map((c) => ({
                        value: String(c.value),
                        label: c.label,
                    }))}
                    onChange={(v) =>
                        applyFilters({ client: v === ALL ? null : Number(v) })
                    }
                />
            )}
            <FilterSelect
                label="Platform"
                value={filters.platform ?? ALL}
                options={options.platforms}
                onChange={(v) =>
                    applyFilters({ platform: v === ALL ? null : v })
                }
            />
            <FilterSelect
                label="Sentiment"
                value={filters.sentiment ?? ALL}
                options={options.sentiments}
                onChange={(v) =>
                    applyFilters({ sentiment: v === ALL ? null : v })
                }
            />
            <FilterSelect
                label="Status"
                value={filters.status ?? ALL}
                options={options.statuses}
                onChange={(v) => applyFilters({ status: v === ALL ? null : v })}
            />
            {showAssignment && (
                <FilterSelect
                    label="Assignee"
                    value={filters.assignee ?? ALL}
                    options={[
                        { value: 'me', label: 'Mine' },
                        { value: 'unassigned', label: 'Unassigned' },
                        ...options.staff.map((s) => ({
                            value: String(s.value),
                            label: s.label,
                        })),
                    ]}
                    onChange={(v) =>
                        applyFilters({ assignee: v === ALL ? null : v })
                    }
                />
            )}

            <Button
                variant={filters.reply_needed ? 'brand' : 'outline'}
                size="sm"
                onClick={() =>
                    applyFilters({ reply_needed: !filters.reply_needed })
                }
            >
                Needs a reply
            </Button>
        </div>
    );
}

function FilterSelect({
    label,
    value,
    options,
    onChange,
}: {
    label: string;
    value: string;
    options: Option[];
    onChange: (value: string) => void;
}) {
    return (
        <Select value={value} onValueChange={onChange}>
            <SelectTrigger size="sm" className="w-auto min-w-[120px]">
                <SelectValue placeholder={label} />
            </SelectTrigger>
            <SelectContent>
                <SelectItem value={ALL}>{label}: all</SelectItem>
                {options.map((o) => (
                    <SelectItem key={o.value} value={o.value}>
                        {o.label}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}

function ConversationList({
    rows,
    selectedId,
    onSelect,
    multiClient,
}: {
    rows: ConversationRow[];
    selectedId: number | null;
    onSelect: (id: number) => void;
    multiClient: boolean;
}) {
    if (rows.length === 0) {
        return (
            <EmptyState
                icon={MessageSquareHeart}
                title="Inbox zero."
                description="No comments match this filter. New comments land here within the hour, or hit Refresh."
            />
        );
    }

    return (
        <div className="divide-border/70 border-border bg-card divide-y overflow-hidden rounded-2xl border shadow-sm">
            {rows.map((row) => (
                <ConversationListRow
                    key={row.id}
                    row={row}
                    active={row.id === selectedId}
                    onSelect={() => onSelect(row.id)}
                    multiClient={multiClient}
                />
            ))}
        </div>
    );
}

function ConversationListRow({
    row,
    active,
    onSelect,
    multiClient,
}: {
    row: ConversationRow;
    active: boolean;
    onSelect: () => void;
    multiClient: boolean;
}) {
    const Icon = platformIcon(row.platform);

    return (
        <button
            type="button"
            onClick={onSelect}
            className={cn(
                'hover:bg-muted/50 flex w-full items-start gap-3 px-4 py-3 text-left transition-colors',
                active && 'bg-muted/60',
            )}
        >
            <span className="bg-accent text-foreground relative mt-0.5 flex size-9 shrink-0 items-center justify-center rounded-full text-sm font-semibold">
                {initials(row.author.name)}
                <span className="bg-card absolute -right-0.5 -bottom-0.5 flex size-4 items-center justify-center rounded-full">
                    <Icon className="text-muted-foreground size-3" />
                </span>
            </span>

            <span className="min-w-0 flex-1">
                <span className="flex items-center gap-2">
                    <span className="text-foreground truncate text-sm font-semibold">
                        {row.author.name ?? 'Someone'}
                    </span>
                    {row.is_reply_needed && (
                        <span
                            className="bg-destructive size-1.5 shrink-0 rounded-full"
                            aria-label="Needs a reply"
                        />
                    )}
                    <span className="text-muted-foreground ml-auto shrink-0 font-mono text-[11px]">
                        {relativeTime(row.last_message_at)}
                    </span>
                </span>
                <span className="text-muted-foreground mt-0.5 block truncate text-sm">
                    {row.snippet ?? '—'}
                </span>
                <span className="mt-1.5 flex flex-wrap items-center gap-1.5">
                    <StatusPill kind="sentiment" value={row.sentiment} />
                    {multiClient && row.client.name && (
                        <span className="text-muted-foreground truncate font-mono text-[11px]">
                            {row.client.name}
                        </span>
                    )}
                    {row.assignee && (
                        <span className="text-muted-foreground ml-auto inline-flex items-center gap-1 font-mono text-[11px]">
                            <UserRound className="size-3" />
                            {row.assignee.name}
                        </span>
                    )}
                </span>
            </span>
        </button>
    );
}

function ThreadPane({
    detail,
    loading,
    canAct,
    aiEnabled,
    savedReplies,
    staff,
    multiClient,
    showAssignment,
    routes,
    onBack,
    onChanged,
}: {
    detail: ConversationDetail | null;
    loading: boolean;
    canAct: boolean;
    aiEnabled: boolean;
    savedReplies: SavedReply[];
    staff: NumberOption[];
    multiClient: boolean;
    showAssignment: boolean;
    routes: InboxRoutes;
    onBack: () => void;
    onChanged: (next: ConversationDetail) => void;
}) {
    if (loading) {
        return (
            <div className="border-border bg-card flex h-full min-h-[320px] items-center justify-center rounded-2xl border shadow-sm">
                <RefreshCw className="text-muted-foreground size-5 animate-spin" />
            </div>
        );
    }

    if (!detail) {
        return (
            <div className="hidden h-full min-h-[320px] lg:flex">
                <EmptyState
                    icon={MessageSquareHeart}
                    title="Pick a conversation."
                    description="Choose a comment on the left to read the thread and reply."
                />
            </div>
        );
    }

    return (
        <div className="border-border bg-card flex h-full flex-col overflow-hidden rounded-2xl border shadow-sm">
            <ThreadHeader
                detail={detail}
                canAct={canAct}
                staff={staff}
                multiClient={multiClient}
                showAssignment={showAssignment}
                routes={routes}
                onBack={onBack}
                onChanged={onChanged}
            />
            <ThreadMessages detail={detail} />
            {detail.can.reply && detail.status !== 'resolved' && (
                <Composer
                    detail={detail}
                    aiEnabled={aiEnabled}
                    savedReplies={savedReplies}
                    routes={routes}
                    onChanged={onChanged}
                />
            )}
        </div>
    );
}

function ThreadHeader({
    detail,
    canAct,
    staff,
    multiClient,
    showAssignment,
    routes,
    onBack,
    onChanged,
}: {
    detail: ConversationDetail;
    canAct: boolean;
    staff: NumberOption[];
    multiClient: boolean;
    showAssignment: boolean;
    routes: InboxRoutes;
    onBack: () => void;
    onChanged: (next: ConversationDetail) => void;
}) {
    const act = async (
        action: string,
        extra: Record<string, string | number | null> = {},
    ) => {
        const result = await http.patch<{ conversation: ConversationDetail }>(
            routes.update({ conversation: detail.id }).url,
            { action, ...extra },
            { toastOnError: true },
        );
        if (result.ok) {
            onChanged(result.data.conversation);
            toast.success(labelFor(action));
        }
    };

    const snooze = () => act('snooze', { snoozed_until: tomorrow9am() });

    return (
        <div className="border-border flex items-center gap-3 border-b px-4 py-3">
            <Button
                variant="ghost"
                size="icon"
                className="lg:hidden"
                onClick={onBack}
            >
                <ArrowLeft />
            </Button>
            <div className="min-w-0 flex-1">
                <p className="text-foreground truncate text-sm font-semibold">
                    {detail.author.name ?? 'Someone'}
                    {detail.author.handle && (
                        <span className="text-muted-foreground ml-1.5 font-mono text-[11px] font-normal">
                            {detail.author.handle}
                        </span>
                    )}
                </p>
                <p className="text-muted-foreground truncate font-mono text-[11px]">
                    {detail.platform_label}
                    {multiClient && detail.client.name
                        ? ` · ${detail.client.name}`
                        : ''}
                </p>
            </div>

            <StatusPill kind="inbox" value={detail.status} />

            {canAct && showAssignment && (
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <Button variant="outline" size="sm">
                            <UserRound />
                            {detail.assignee
                                ? detail.assignee.name.split(' ')[0]
                                : 'Assign'}
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                        <DropdownMenuLabel>Assign to</DropdownMenuLabel>
                        <DropdownMenuItem
                            onClick={() => act('assign', { assigned_to: null })}
                        >
                            Unassigned
                        </DropdownMenuItem>
                        <DropdownMenuSeparator />
                        {staff.map((s) => (
                            <DropdownMenuItem
                                key={s.value}
                                onClick={() =>
                                    act('assign', { assigned_to: s.value })
                                }
                            >
                                {s.label}
                            </DropdownMenuItem>
                        ))}
                    </DropdownMenuContent>
                </DropdownMenu>
            )}

            {canAct && detail.status !== 'resolved' && (
                <>
                    <Button
                        variant="ghost"
                        size="icon"
                        title="Snooze until tomorrow"
                        onClick={snooze}
                    >
                        <Clock />
                    </Button>
                    <Button
                        variant="brand"
                        size="sm"
                        onClick={() => act('resolve')}
                    >
                        <CheckCircle2 />
                        Resolve
                    </Button>
                </>
            )}
            {canAct && detail.status === 'resolved' && (
                <Button
                    variant="outline"
                    size="sm"
                    onClick={() => act('reopen')}
                >
                    Reopen
                </Button>
            )}

            {detail.permalink && (
                <Button
                    variant="ghost"
                    size="icon"
                    asChild
                    title="Open on the network"
                >
                    <a
                        href={detail.permalink}
                        target="_blank"
                        rel="noreferrer noopener"
                    >
                        <ExternalLink />
                    </a>
                </Button>
            )}
        </div>
    );
}

function ThreadMessages({ detail }: { detail: ConversationDetail }) {
    return (
        <div className="flex-1 space-y-4 overflow-y-auto px-4 py-4">
            {detail.post && (
                <div className="bg-muted/40 flex items-center gap-3 rounded-xl p-3">
                    {detail.post.thumbnail_url && (
                        <img
                            src={detail.post.thumbnail_url}
                            alt=""
                            className="size-12 shrink-0 rounded-lg object-cover"
                        />
                    )}
                    <p className="text-muted-foreground line-clamp-2 text-xs">
                        {detail.post.caption || 'The post this comment is on.'}
                    </p>
                </div>
            )}

            {detail.messages.map((message) => (
                <MessageBubble key={message.id} message={message} />
            ))}
        </div>
    );
}

function MessageBubble({ message }: { message: Message }) {
    const outbound = message.direction === 'outbound';

    return (
        <div
            className={cn(
                'flex flex-col',
                outbound ? 'items-end' : 'items-start',
            )}
        >
            <div
                className={cn(
                    'max-w-[85%] rounded-2xl px-3.5 py-2.5 text-sm',
                    outbound ? 'bg-ink text-cream' : 'bg-muted text-foreground',
                )}
            >
                {!outbound && message.author_name && (
                    <p className="mb-0.5 text-xs font-semibold">
                        {message.author_name}
                    </p>
                )}
                <p className="whitespace-pre-wrap">{message.body}</p>
            </div>
            <p className="text-muted-foreground mt-1 flex items-center gap-1.5 px-1 font-mono text-[11px]">
                {outbound && message.sent_by ? `${message.sent_by} · ` : ''}
                {message.posted_at ? formatDateTime(message.posted_at) : ''}
                {message.delivery_status === 'failed' && (
                    <span className="text-destructive">
                        · {message.delivery_error ?? 'failed'}
                    </span>
                )}
                {message.delivery_status === 'pending' && (
                    <span>· sending…</span>
                )}
            </p>
        </div>
    );
}

export function Composer({
    detail,
    aiEnabled,
    savedReplies,
    routes = agencyRoutes,
    onChanged,
}: {
    detail: ConversationDetail;
    aiEnabled: boolean;
    savedReplies: SavedReply[];
    routes?: Pick<InboxRoutes, 'reply' | 'draft'>;
    onChanged: (next: ConversationDetail) => void;
}) {
    const [body, setBody] = useState('');
    const [savedReplyId, setSavedReplyId] = useState<number | null>(null);
    const [sending, setSending] = useState(false);
    const [drafting, setDrafting] = useState(false);

    const available = useMemo(
        () =>
            savedReplies.filter(
                (r) => r.client_id === null || r.client_id === detail.client.id,
            ),
        [savedReplies, detail.client.id],
    );

    const insertSavedReply = (reply: SavedReply) => {
        setBody((current) =>
            current ? `${current}\n${reply.body}` : reply.body,
        );
        setSavedReplyId(reply.id);
    };

    const draftWithAi = async () => {
        setDrafting(true);
        const result = await http.post<{ text: string }>(
            routes.draft({ conversation: detail.id }).url,
            {},
            {
                rateLimitMessage:
                    'The AI assistant is busy — try again in a moment.',
                toastOnError: true,
            },
        );
        setDrafting(false);
        if (result.ok) {
            if (result.data.text.trim() === '') {
                toast.message(
                    "Couldn't draft an on-brand reply — write your own.",
                );
                return;
            }
            setBody(result.data.text);
            setSavedReplyId(null);
        }
    };

    const send = async () => {
        if (body.trim() === '') return;
        setSending(true);
        const result = await http.post<{
            conversation: ConversationDetail;
            message?: string;
        }>(routes.reply({ conversation: detail.id }).url, {
            body,
            saved_reply_id: savedReplyId,
        });
        setSending(false);
        if (result.ok) {
            setBody('');
            setSavedReplyId(null);
            onChanged(result.data.conversation);
            toast.success('Reply sent.');
        } else {
            toast.error(result.message);
        }
    };

    return (
        <div className="border-border border-t p-3">
            <Textarea
                value={body}
                onChange={(e) => setBody(e.target.value)}
                placeholder="Write a reply… a human always sends."
                rows={3}
                className="resize-none"
                onKeyDown={(e) => {
                    if ((e.metaKey || e.ctrlKey) && e.key === 'Enter') {
                        e.preventDefault();
                        void send();
                    }
                }}
            />
            <div className="mt-2 flex flex-wrap items-center gap-2">
                {available.length > 0 && (
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button variant="outline" size="sm">
                                Saved replies
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="start" className="max-w-xs">
                            {available.map((reply) => (
                                <DropdownMenuItem
                                    key={reply.id}
                                    onClick={() => insertSavedReply(reply)}
                                    className="flex-col items-start gap-0.5"
                                >
                                    <span className="font-medium">
                                        {reply.title}
                                    </span>
                                    <span className="text-muted-foreground line-clamp-1 text-xs">
                                        {reply.body}
                                    </span>
                                </DropdownMenuItem>
                            ))}
                        </DropdownMenuContent>
                    </DropdownMenu>
                )}

                {aiEnabled && (
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={draftWithAi}
                        disabled={drafting}
                    >
                        <Sparkles className={cn(drafting && 'animate-pulse')} />
                        {drafting ? 'Drafting…' : 'Draft with AI'}
                    </Button>
                )}

                <Button
                    className="ml-auto"
                    onClick={send}
                    disabled={sending || body.trim() === ''}
                >
                    <Send />
                    {sending ? 'Sending…' : 'Send'}
                </Button>
            </div>
        </div>
    );
}

function labelFor(action: string): string {
    switch (action) {
        case 'assign':
            return 'Assignment updated.';
        case 'snooze':
            return 'Snoozed until tomorrow.';
        case 'resolve':
            return 'Marked resolved.';
        case 'reopen':
            return 'Reopened.';
        default:
            return 'Updated.';
    }
}

function tomorrow9am(): string {
    const date = new Date();
    date.setDate(date.getDate() + 1);
    date.setHours(9, 0, 0, 0);
    return date.toISOString();
}

function relativeTime(iso: string | null): string {
    if (!iso) return '';
    const then = new Date(iso).getTime();
    const diff = Date.now() - then;
    const minutes = Math.round(diff / 60000);
    if (minutes < 1) return 'now';
    if (minutes < 60) return `${minutes}m`;
    const hours = Math.round(minutes / 60);
    if (hours < 24) return `${hours}h`;
    const days = Math.round(hours / 24);
    return `${days}d`;
}
