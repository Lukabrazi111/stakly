import { Link } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowRight,
    BadgeCheck,
    Ban,
    Check,
    Clock,
    Hand,
    ShieldAlert,
    ShieldCheck,
    Swords,
    Trophy,
    X,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useT } from '@/lib/i18n';
import { formatNotificationTime } from '@/lib/notifications-format';
import { cn } from '@/lib/utils';
import type { Notification, NotificationEventType } from '@/types/notification';

interface Props {
    notification: Notification;
    onMarkRead: (id: string) => void;
}

const ICONS: Record<NotificationEventType, LucideIcon> = {
    listing_taken: Swords,
    listing_expired: Clock,
    team_match_started: Swords,
    match_settled: Trophy,
    match_manual_review: ShieldAlert,
    dispute_opened: AlertTriangle,
    dispute_resolved: ShieldCheck,
    cancellation_requested: Hand,
    cancellation_accepted: Check,
    cancellation_rejected: X,
    account_banned: Ban,
    account_restored: BadgeCheck,
};

export function NotificationCard({ notification, onMarkRead }: Props) {
    const t = useT();
    const Icon =
        (notification.event_type && ICONS[notification.event_type]) || Swords;
    const isRead = notification.read_at !== null;

    return (
        <article
            className={cn(
                'flex items-start gap-4 px-5 py-5 transition-colors duration-150',
                !isRead && 'bg-primary/5',
            )}
        >
            <span
                className={cn(
                    'flex size-10 shrink-0 items-center justify-center rounded-full',
                    !isRead
                        ? 'bg-primary/15 text-primary'
                        : 'bg-muted text-muted-foreground',
                )}
            >
                <Icon className="size-5" />
            </span>

            <div className="min-w-0 flex-1">
                <div className="flex items-start gap-3">
                    <h3
                        className={cn(
                            'flex-1 text-base text-foreground',
                            !isRead && 'font-semibold',
                        )}
                    >
                        {notification.title}
                    </h3>
                    {!isRead && (
                        <span
                            aria-label={t('Unread')}
                            className="mt-2 size-2 shrink-0 rounded-full bg-primary"
                        />
                    )}
                </div>

                <p className="mt-1.5 text-sm text-muted-foreground">
                    {notification.body}
                </p>

                <div className="mt-4 flex items-center justify-between gap-3">
                    <time className="text-xs text-muted-foreground">
                        {formatNotificationTime(notification.created_at, t, {
                            capitalizeJustNow: true,
                            absoluteFormat: {
                                month: 'short',
                                day: 'numeric',
                                year: 'numeric',
                            },
                        })}
                    </time>

                    <div className="flex items-center gap-4">
                        {!isRead && (
                            <button
                                type="button"
                                onClick={() => onMarkRead(notification.id)}
                                className="cursor-pointer text-xs text-muted-foreground transition-colors hover:text-foreground"
                            >
                                {t('Mark read')}
                            </button>
                        )}
                        {notification.action_url && (
                            <Link
                                href={notification.action_url}
                                onClick={() => onMarkRead(notification.id)}
                                className="inline-flex cursor-pointer items-center gap-1 text-sm text-primary transition-colors hover:text-primary/80"
                            >
                                {t('View')}
                                <ArrowRight className="size-3.5" />
                            </Link>
                        )}
                    </div>
                </div>
            </div>
        </article>
    );
}
