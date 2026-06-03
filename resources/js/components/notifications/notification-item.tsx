import { Link } from '@inertiajs/react';
import {
    AlertTriangle,
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
    onClick: (id: string) => void;
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

function relativeTime(iso: string): string {
    const seconds = Math.max(
        0,
        Math.floor((Date.now() - new Date(iso).getTime()) / 1000),
    );

    if (seconds < 60) {
        return 'now';
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

    const weeks = Math.floor(days / 7);

    if (weeks < 4) {
        return `${weeks}w ago`;
    }

    return new Date(iso).toLocaleDateString();
}

export function NotificationItem({ notification, onClick }: Props) {
    const Icon = notification.event_type
        ? ICONS[notification.event_type]
        : Swords;
    const isRead = notification.read_at !== null;
    const href = notification.action_url ?? '#';

    return (
        <Link
            href={href}
            onClick={() => onClick(notification.id)}
            className={cn(
                'flex cursor-pointer items-start gap-3 px-4 py-3 transition-colors duration-150 ease-out hover:bg-primary/10',
                !isRead && 'bg-primary/5',
            )}
        >
            <span
                className={cn(
                    'mt-0.5 flex size-9 shrink-0 items-center justify-center rounded-full',
                    !isRead
                        ? 'bg-primary/15 text-primary'
                        : 'bg-muted text-muted-foreground',
                )}
            >
                <Icon className="size-4" />
            </span>
            <div className="min-w-0 flex-1">
                <div className="flex items-baseline justify-between gap-2">
                    <p
                        className={cn(
                            'truncate text-sm text-foreground',
                            !isRead && 'font-semibold',
                        )}
                    >
                        {notification.title}
                    </p>
                    <span className="shrink-0 text-xs text-muted-foreground">
                        {relativeTime(notification.created_at)}
                    </span>
                </div>
                <p className="mt-0.5 line-clamp-2 text-xs text-muted-foreground">
                    {notification.body}
                </p>
            </div>
        </Link>
    );
}
