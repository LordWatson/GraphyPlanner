import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    index as indexNotifications,
    read as readNotification,
    readAll as readAllNotifications,
} from '@/routes/notifications';
import { router, usePage } from '@inertiajs/react';
import { Bell, CheckCheck } from 'lucide-react';
import { useEffect, useState } from 'react';
import { http } from '@/lib/http';

interface NotificationItem {
    id: string;
    title: string | null;
    body: string | null;
    url: string | null;
    read_at: string | null;
    created_at: string | null;
}

/**
 * The notification bell shown in the app header (and the client-portal header): shows an unread
 * badge (from the `unreadNotificationsCount` shared Inertia prop, always fresh) and lazy-loads
 * the recent list from `notifications.index` the first time it's opened. Clicking a notification
 * marks it as read and navigates to wherever it points (`NotificationController::read`).
 */
export function NotificationBell() {
    const { props } = usePage();
    const unreadCount = props.unreadNotificationsCount ?? 0;

    const [open, setOpen] = useState(false);
    const [loading, setLoading] = useState(false);
    const [loadingMore, setLoadingMore] = useState(false);
    const [unreadOnly, setUnreadOnly] = useState(false);
    const [nextBefore, setNextBefore] = useState<string | null>(null);
    const [hasMore, setHasMore] = useState(false);
    const [notifications, setNotifications] = useState<
        NotificationItem[] | null
    >(null);

    type Page = {
        notifications: NotificationItem[];
        next_before: string | null;
        has_more: boolean;
    };

    const load = (before: string | null, replace: boolean) => {
        if (before) {
            setLoadingMore(true);
        } else {
            setLoading(true);
        }
        const query: Record<string, string> = {};
        if (before) query.before = before;
        if (unreadOnly) query.filter = 'unread';

        void http
            .get<Page>(indexNotifications.url({ query }), {
                toastOnError: true,
            })
            .then((result) => {
                if (!result.ok) {
                    if (replace) setNotifications([]);
                    return;
                }
                setNotifications((prev) =>
                    replace || prev === null
                        ? result.data.notifications
                        : [...prev, ...result.data.notifications],
                );
                setNextBefore(result.data.next_before);
                setHasMore(result.data.has_more);
            })
            .finally(() => {
                setLoading(false);
                setLoadingMore(false);
            });
    };

    // Load the first page when opened, and reload when the unread filter changes while open.
    useEffect(() => {
        if (!open) {
            return;
        }
        load(null, true);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, unreadOnly]);

    const handleSelect = (notification: NotificationItem) => {
        setOpen(false);
        router.post(readNotification.url(notification.id));
    };

    const handleMarkAllAsRead = () => {
        router.post(
            readAllNotifications.url(),
            {},
            {
                onSuccess: () => load(null, true),
            },
        );
    };

    return (
        <DropdownMenu open={open} onOpenChange={setOpen}>
            <DropdownMenuTrigger asChild>
                <Button
                    variant="outline"
                    size="icon"
                    className="relative size-8 rounded-full"
                    aria-label="Notifications"
                >
                    <Bell className="size-4" />
                    {unreadCount > 0 && (
                        <Badge
                            variant="solid"
                            className="border-background bg-primary text-primary-foreground absolute -top-1.5 -right-1.5 h-[18px] min-w-[18px] justify-center border-2 px-1 font-mono text-[9.5px]"
                        >
                            {unreadCount > 99 ? '99+' : unreadCount}
                        </Badge>
                    )}
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-[22rem] p-0">
                <div className="flex items-center justify-between px-4 pt-3.5 pb-3">
                    <DropdownMenuLabel className="font-display p-0 text-lg font-normal">
                        Inbox
                    </DropdownMenuLabel>
                    {unreadCount > 0 && (
                        <button
                            type="button"
                            onClick={handleMarkAllAsRead}
                            className="text-muted-foreground hover:text-foreground flex items-center gap-1 text-xs"
                        >
                            <CheckCheck className="size-3.5" />
                            Mark all read
                        </button>
                    )}
                </div>
                <div className="flex gap-1.5 px-4 pb-2">
                    <button
                        type="button"
                        onClick={() => setUnreadOnly(false)}
                        className={
                            unreadOnly
                                ? 'text-muted-foreground text-xs'
                                : 'text-foreground text-xs font-medium'
                        }
                    >
                        All
                    </button>
                    <span className="text-muted-foreground text-xs">·</span>
                    <button
                        type="button"
                        onClick={() => setUnreadOnly(true)}
                        className={
                            unreadOnly
                                ? 'text-foreground text-xs font-medium'
                                : 'text-muted-foreground text-xs'
                        }
                    >
                        Unread
                    </button>
                </div>
                <DropdownMenuSeparator className="m-0" />
                <div className="max-h-96 overflow-y-auto p-1.5">
                    {loading && (
                        <p className="text-muted-foreground px-2 py-4 text-center text-sm">
                            Loading…
                        </p>
                    )}
                    {!loading &&
                        notifications !== null &&
                        notifications.length === 0 && (
                            <p className="text-muted-foreground px-2 py-4 text-center text-sm">
                                You're all caught up.
                            </p>
                        )}
                    {!loading &&
                        notifications?.map((notification) => (
                            <DropdownMenuItem
                                key={notification.id}
                                className="flex flex-col items-start gap-0.5 px-2.5 py-2.5 whitespace-normal"
                                onSelect={() => handleSelect(notification)}
                            >
                                <span className="flex w-full items-center gap-2 font-medium">
                                    {!notification.read_at && (
                                        <span className="bg-primary inline-block size-1.5 shrink-0 rounded-full" />
                                    )}
                                    {notification.title}
                                </span>
                                {notification.body && (
                                    <span className="text-muted-foreground text-xs">
                                        {notification.body}
                                    </span>
                                )}
                            </DropdownMenuItem>
                        ))}
                    {!loading && hasMore && (
                        <button
                            type="button"
                            onClick={() => load(nextBefore, false)}
                            disabled={loadingMore}
                            className="text-muted-foreground hover:text-foreground w-full px-2 py-2 text-center text-xs"
                        >
                            {loadingMore ? 'Loading…' : 'Load more'}
                        </button>
                    )}
                </div>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
