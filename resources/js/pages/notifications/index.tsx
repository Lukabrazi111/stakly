import { router } from '@inertiajs/react';
import { CheckCheck, Inbox } from 'lucide-react';
import { useEffect, useState } from 'react';
import { NotificationCard } from '@/components/notifications/notification-card';
import { NotificationPagination } from '@/components/notifications/notification-pagination';
import { useNotificationContext } from '@/components/notifications/notification-provider';
import { BackLink } from '@/components/site/back-link';
import { PageMeta } from '@/components/site/page-meta';
import PlayerHubLayout from '@/layouts/player-hub-layout';
import { useT } from '@/lib/i18n';
import {
    index as notificationsIndex,
    read as notificationsRead,
    readAll as notificationsReadAll,
    seen as notificationsSeen,
} from '@/routes/notifications';
import type { Paginator } from '@/types';
import type { Notification } from '@/types/notification';

type Filter = 'all' | 'unread';

interface Props {
    notifications: Paginator<Notification>;
    filter: Filter;
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

export default function NotificationsIndex({ notifications, filter }: Props) {
    const t = useT();
    const { clearUnread } = useNotificationContext();
    const [marking, setMarking] = useState(false);
    const [items, setItems] = useState(notifications.data);

    // Re-sync when Inertia props change (pagination, filter switch, reload).
    useEffect(() => {
        setItems(notifications.data);
    }, [notifications.data]);

    useEffect(() => {
        postJson(notificationsSeen().url);
        clearUnread();
    }, [clearUnread]);

    const goToFilter = (next: Filter) => {
        if (next === filter) {
            return;
        }

        router.get(
            notificationsIndex().url,
            next === 'unread' ? { filter: 'unread' } : {},
            {
                preserveState: false,
                preserveScroll: false,
                replace: true,
            },
        );
    };

    const handleMarkRead = (id: string) => {
        postJson(notificationsRead({ notification: id }).url);

        setItems((prev) => {
            if (filter === 'unread') {
                return prev.filter((n) => n.id !== id);
            }

            return prev.map((n) =>
                n.id === id ? { ...n, read_at: new Date().toISOString() } : n,
            );
        });
    };

    const handleMarkAllRead = () => {
        setMarking(true);
        postJson(notificationsReadAll().url).finally(() => {
            router.reload({
                only: ['notifications'],
                onFinish: () => setMarking(false),
            });
        });
    };

    const isEmpty = items.length === 0;
    const hasUnread = items.some((n) => n.read_at === null);

    return (
        <PlayerHubLayout>
            <PageMeta
                title={t('Notifications')}
                description={t('Your notifications history.')}
                noindex
            />

            <div className="mx-auto max-w-3xl px-4 py-10 md:px-6 md:py-14">
                <BackLink fallback="/" />

                <header className="mt-4 mb-6 flex flex-wrap items-baseline justify-between gap-4">
                    <div>
                        <h1 className="font-display text-3xl font-bold tracking-tight md:text-4xl">
                            {t('Notifications')}
                        </h1>
                        <p className="mt-2 text-sm text-muted-foreground">
                            {t("Everything that's happened, newest first.")}
                        </p>
                    </div>
                    {hasUnread && (
                        <button
                            type="button"
                            onClick={handleMarkAllRead}
                            disabled={marking}
                            className="inline-flex cursor-pointer items-center gap-2 text-sm text-primary transition-colors hover:text-primary/80 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            <CheckCheck className="size-4" />
                            {t('Mark all read')}
                        </button>
                    )}
                </header>

                <div className="mb-6 flex flex-wrap items-center gap-2">
                    <FilterChip
                        active={filter === 'all'}
                        onClick={() => goToFilter('all')}
                    >
                        {t('All')}
                    </FilterChip>
                    <FilterChip
                        active={filter === 'unread'}
                        onClick={() => goToFilter('unread')}
                    >
                        {t('Unread')}
                    </FilterChip>
                </div>

                {isEmpty ? (
                    <div className="rounded-2xl border border-dashed border-border/60 p-10 text-center">
                        <div className="mx-auto inline-flex size-12 items-center justify-center rounded-full bg-primary/10 text-primary">
                            <Inbox className="size-5" />
                        </div>
                        <p className="mt-3 text-sm font-medium text-foreground">
                            {filter === 'unread'
                                ? t('No unread notifications')
                                : t('No notifications yet')}
                        </p>
                        <p className="mt-1 text-sm text-muted-foreground">
                            {filter === 'unread'
                                ? t("You're all caught up.")
                                : t(
                                      "When something happens — a listing taken, a match settled — you'll see it here.",
                                  )}
                        </p>
                    </div>
                ) : (
                    <>
                        <div className="divide-y divide-border/40 overflow-hidden rounded-2xl border border-border/60 bg-card/60">
                            {items.map((notification) => (
                                <NotificationCard
                                    key={notification.id}
                                    notification={notification}
                                    onMarkRead={handleMarkRead}
                                />
                            ))}
                        </div>

                        <NotificationPagination
                            currentPage={notifications.meta.current_page}
                            lastPage={notifications.meta.last_page}
                            filter={filter}
                        />
                    </>
                )}
            </div>
        </PlayerHubLayout>
    );
}

interface FilterChipProps {
    active: boolean;
    onClick: () => void;
    children: React.ReactNode;
}

function FilterChip({ active, onClick, children }: FilterChipProps) {
    const base =
        'inline-flex cursor-pointer items-center rounded-full border px-3.5 py-1.5 text-xs font-medium transition-colors duration-150 ease-out focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/25 focus-visible:ring-offset-2 focus-visible:ring-offset-background';
    const stateClasses = active
        ? 'border-primary/40 bg-primary/15 text-foreground'
        : 'border-border/60 bg-card/60 text-muted-foreground hover:bg-primary/10 hover:text-foreground';

    return (
        <button
            type="button"
            onClick={onClick}
            className={`${base} ${stateClasses}`}
        >
            {children}
        </button>
    );
}
