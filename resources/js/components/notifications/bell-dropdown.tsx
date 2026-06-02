import { Link } from '@inertiajs/react';
import { Bell as BellIcon, X } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { NotificationItem } from '@/components/notifications/notification-item';
import { useNotificationContext } from '@/components/notifications/notification-provider';
import { cn } from '@/lib/utils';
import {
    index as notificationsIndex,
    read as notificationsRead,
    readAll as notificationsReadAll,
    recent as notificationsRecent,
    seen as notificationsSeen,
} from '@/routes/notifications';
import type { Notification } from '@/types/notification';

interface Props {
    onClose: () => void;
    /** Mobile sheet variant — fills parent height, list scrolls in `flex-1`,
     *  header gets right padding to clear the Sheet's absolute X close button. */
    fullHeight?: boolean;
}

function xsrfToken(): string {
    const value = document.cookie
        .split('; ')
        .find((c) => c.startsWith('XSRF-TOKEN='))
        ?.split('=')[1];

    return value ? decodeURIComponent(value) : '';
}

function postJson(url: string): Promise<Response> {
    return fetch(url, {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            'X-XSRF-TOKEN': xsrfToken(),
            'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'same-origin',
    });
}

export function BellDropdown({ onClose, fullHeight = false }: Props) {
    const [notifications, setNotifications] = useState<Notification[]>([]);
    const [loading, setLoading] = useState(true);
    const { lastBroadcast } = useNotificationContext();

    useEffect(() => {
        let cancelled = false;

        postJson(notificationsSeen().url);

        fetch(notificationsRecent().url, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        })
            .then((r) => r.json())
            .then((payload: { data: Notification[] }) => {
                if (!cancelled) {
                    setNotifications(payload.data);
                    setLoading(false);
                }
            })
            .catch(() => {
                if (!cancelled) {
                    setLoading(false);
                }
            });

        return () => {
            cancelled = true;
        };
    }, []);

    useEffect(() => {
        if (!lastBroadcast) {
            return;
        }

        setNotifications((prev) => {
            if (prev.some((n) => n.id === lastBroadcast.id)) {
                return prev;
            }

            return [lastBroadcast, ...prev];
        });
    }, [lastBroadcast]);

    const handleItemClick = useCallback(
        (id: string) => {
            postJson(notificationsRead(id).url);
            setNotifications((prev) =>
                prev.map((n) =>
                    n.id === id && n.read_at === null
                        ? { ...n, read_at: new Date().toISOString() }
                        : n,
                ),
            );
            onClose();
        },
        [onClose],
    );

    const handleMarkAllRead = useCallback(() => {
        postJson(notificationsReadAll().url);
        const now = new Date().toISOString();
        setNotifications((prev) =>
            prev.map((n) => (n.read_at === null ? { ...n, read_at: now } : n)),
        );
    }, []);

    const hasUnread = notifications.some((n) => n.read_at === null);

    return (
        <div className={cn('flex flex-col', fullHeight && 'h-full')}>
            <div className="flex items-center justify-between border-b border-border/60 px-4 py-3">
                <span className="text-sm font-semibold text-foreground">
                    Notifications
                </span>
                <div className="flex items-center gap-3">
                    {hasUnread && (
                        <button
                            type="button"
                            onClick={handleMarkAllRead}
                            className="cursor-pointer text-xs text-primary transition-colors hover:text-primary/80"
                        >
                            Mark all read
                        </button>
                    )}
                    {fullHeight && (
                        <button
                            type="button"
                            onClick={onClose}
                            aria-label="Close"
                            className="inline-flex size-8 cursor-pointer items-center justify-center rounded-full text-muted-foreground transition-colors duration-150 ease-out hover:bg-primary/10 hover:text-primary"
                        >
                            <X className="size-4" />
                        </button>
                    )}
                </div>
            </div>

            <div
                className={cn(
                    'flex-1 overflow-y-auto',
                    !fullHeight && 'max-h-[28rem]',
                )}
            >
                {loading ? (
                    <div className="space-y-3 px-4 py-4">
                        {[0, 1, 2].map((i) => (
                            <div
                                key={i}
                                className="flex items-start gap-3 py-1"
                            >
                                <div className="size-9 animate-pulse rounded-full bg-muted" />
                                <div className="flex-1 space-y-2 py-1">
                                    <div className="h-3 w-3/4 animate-pulse rounded bg-muted" />
                                    <div className="h-3 w-1/2 animate-pulse rounded bg-muted" />
                                </div>
                            </div>
                        ))}
                    </div>
                ) : notifications.length === 0 ? (
                    <div className="flex flex-col items-center justify-center px-6 py-12 text-center">
                        <BellIcon className="size-8 text-muted-foreground/50" />
                        <p className="mt-3 text-sm text-muted-foreground">
                            No notifications yet
                        </p>
                        <p className="mt-1 text-xs text-muted-foreground/60">
                            When something happens, you'll see it here.
                        </p>
                    </div>
                ) : (
                    <ul>
                        {notifications.map((notification) => (
                            <li key={notification.id}>
                                <NotificationItem
                                    notification={notification}
                                    onClick={handleItemClick}
                                />
                            </li>
                        ))}
                    </ul>
                )}
            </div>

            <div className="border-t border-border/60">
                <Link
                    href={notificationsIndex().url}
                    onClick={onClose}
                    className="block w-full cursor-pointer px-4 py-3 text-center text-sm text-primary transition-colors hover:bg-primary/10 hover:text-primary/80"
                >
                    View all notifications
                </Link>
            </div>
        </div>
    );
}
