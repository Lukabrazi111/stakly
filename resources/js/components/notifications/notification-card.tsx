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
import { cn } from '@/lib/utils';
import type { Notification, NotificationEventType } from '@/types/notification';

interface Props {
    notification: Notification;
    onMarkRead: (id: string) => void;
}

const ICONS: Record<NotificationEventType, LucideIcon> = {
    listing_taken: Swords,
    listing_expired: Clock,
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

function formatTimestamp(iso: string): string {
    const date = new Date(iso);
    const seconds = Math.max(
        0,
        Math.floor((Date.now() - date.getTime()) / 1000),
    );

    if (seconds < 60) {
        return 'Just now';
    }

    const minutes = Math.floor(seconds / 60);

    if (minutes < 60) {
        return `${minutes}m ago`;
    }

    const hours = Math.floor(minutes / 60);

    if (hours < 24) {
        return `${hours}h ago`;
    }

    const days = Math.floor(hours / 24);

    if (days < 7) {
        return `${days}d ago`;
    }

    return date.toLocaleDateString(undefined, {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    });
}

export function NotificationCard({ notification, onMarkRead }: Props) {
    const Icon = notification.event_type
        ? ICONS[notification.event_type]
        : Swords;
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
                            aria-label="Unread"
                            className="mt-2 size-2 shrink-0 rounded-full bg-primary"
                        />
                    )}
                </div>

                <p className="mt-1.5 text-sm text-muted-foreground">
                    {notification.body}
                </p>

                <div className="mt-4 flex items-center justify-between gap-3">
                    <time className="text-xs text-muted-foreground">
                        {formatTimestamp(notification.created_at)}
                    </time>

                    <div className="flex items-center gap-4">
                        {!isRead && (
                            <button
                                type="button"
                                onClick={() => onMarkRead(notification.id)}
                                className="cursor-pointer text-xs text-muted-foreground transition-colors hover:text-foreground"
                            >
                                Mark read
                            </button>
                        )}
                        {notification.action_url && (
                            <Link
                                href={notification.action_url}
                                onClick={() => onMarkRead(notification.id)}
                                className="inline-flex cursor-pointer items-center gap-1 text-sm text-primary transition-colors hover:text-primary/80"
                            >
                                View
                                <ArrowRight className="size-3.5" />
                            </Link>
                        )}
                    </div>
                </div>
            </div>
        </article>
    );
}
