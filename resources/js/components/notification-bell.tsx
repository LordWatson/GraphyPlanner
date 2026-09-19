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
import { index as indexNotifications, read as readNotification, readAll as readAllNotifications } from '@/routes/notifications';
import { router, usePage } from '@inertiajs/react';
import { Bell, CheckCheck } from 'lucide-react';
import { useEffect, useState } from 'react';

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
    const [notifications, setNotifications] = useState<NotificationItem[] | null>(null);

    useEffect(() => {
        if (!open || notifications !== null) {
            return;
        }

        setLoading(true);

        fetch(indexNotifications.url(), {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        })
            .then((response) => response.json())
            .then((data: { notifications: NotificationItem[] }) => setNotifications(data.notifications))
            .finally(() => setLoading(false));
    }, [open, notifications]);

    const handleSelect = (notification: NotificationItem) => {
        setOpen(false);
        router.post(readNotification.url(notification.id));
    };

    const handleMarkAllAsRead = () => {
        router.post(readAllNotifications.url(), {}, {
            onSuccess: () => setNotifications(null),
        });
    };

    return (
        <DropdownMenu open={open} onOpenChange={setOpen}>
            <DropdownMenuTrigger asChild>
                <Button variant="ghost" size="icon" className="relative" aria-label="Notifications">
                    <Bell className="size-5" />
                    {unreadCount > 0 && (
                        <Badge
                            variant="destructive"
                            className="absolute -top-1 -right-1 h-5 min-w-5 justify-center rounded-full px-1 text-[10px]"
                        >
                            {unreadCount > 99 ? '99+' : unreadCount}
                        </Badge>
                    )}
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-80">
                <div className="flex items-center justify-between px-2 py-1.5">
                    <DropdownMenuLabel className="p-0">Notifications</DropdownMenuLabel>
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
                <DropdownMenuSeparator />
                <div className="max-h-96 overflow-y-auto">
                    {loading && <p className="text-muted-foreground px-2 py-4 text-center text-sm">Loading…</p>}
                    {!loading && notifications !== null && notifications.length === 0 && (
                        <p className="text-muted-foreground px-2 py-4 text-center text-sm">You're all caught up.</p>
                    )}
                    {!loading &&
                        notifications?.map((notification) => (
                            <DropdownMenuItem
                                key={notification.id}
                                className="flex flex-col items-start gap-0.5 whitespace-normal py-2"
                                onSelect={() => handleSelect(notification)}
                            >
                                <span className="flex w-full items-center gap-2 font-medium">
                                    {!notification.read_at && <span className="bg-primary inline-block size-1.5 shrink-0 rounded-full" />}
                                    {notification.title}
                                </span>
                                {notification.body && <span className="text-muted-foreground text-xs">{notification.body}</span>}
                            </DropdownMenuItem>
                        ))}
                </div>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
