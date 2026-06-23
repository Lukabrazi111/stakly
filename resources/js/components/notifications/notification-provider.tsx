import { router, usePage } from '@inertiajs/react';
import { useEchoNotification } from '@laravel/echo-react';
import {
    createContext,
    useCallback,
    useContext,
    useEffect,
    useState,
} from 'react';
import type { ReactNode } from 'react';
import { useNotificationSound } from '@/hooks/use-notification-sound';
import type { Notification, NotificationEventType } from '@/types/notification';

interface BroadcastPayload {
    id: string;
    type: string;
    event_type: NotificationEventType;
    title: string;
    body: string;
    action_url: string | null;
    related_id: number | null;
}

// Events whose arrival changes a value in the shared `auth` prop, so the
// provider re-pulls it (scroll/state preserved) rather than waiting for the next
// navigation. Moderation flips the ban banner; the match events cross the
// in-progress boundary (Pending/Disputed/ManualReview), moving
// `active_matches_count` (sidebar + tab badge). Same-set shifts (dispute_opened,
// manual_review, cancellation requested/rejected) don't change it — excluded.
const AUTH_RESYNC_EVENTS = new Set<NotificationEventType>([
    'account_banned',
    'account_restored',
    'listing_taken',
    'team_match_started',
    'match_settled',
    'dispute_resolved',
    'cancellation_accepted',
]);

interface NotificationContextValue {
    unreadCount: number;
    clearUnread: () => void;
    lastBroadcast: Notification | null;
}

const defaultContext: NotificationContextValue = {
    unreadCount: 0,
    clearUnread: () => {},
    lastBroadcast: null,
};

const NotificationContext =
    createContext<NotificationContextValue>(defaultContext);

export function useNotificationContext(): NotificationContextValue {
    return useContext(NotificationContext);
}

interface ProviderProps {
    children: ReactNode;
}

export function NotificationProvider({ children }: ProviderProps) {
    const { auth } = usePage().props;
    const user = auth.user;

    if (!user) {
        return <>{children}</>;
    }

    return (
        <AuthedNotificationProvider
            userId={user.id}
            initialCount={user.unread_notifications_count}
        >
            {children}
        </AuthedNotificationProvider>
    );
}

interface AuthedProps {
    userId: number;
    initialCount: number;
    children: ReactNode;
}

function AuthedNotificationProvider({
    userId,
    initialCount,
    children,
}: AuthedProps) {
    const { auth } = usePage().props;
    const soundMap = auth.user?.notification_sound_map ?? {};
    const [unreadCount, setUnreadCount] = useState(initialCount);
    const [lastBroadcast, setLastBroadcast] = useState<Notification | null>(
        null,
    );
    const playSound = useNotificationSound();

    // Re-sync the badge count from the Inertia share on every page visit —
    // server is authoritative (e.g. mark-read on another tab decrements there).
    useEffect(() => {
        setUnreadCount(initialCount);
    }, [initialCount]);

    const clearUnread = useCallback(() => {
        setUnreadCount(0);
    }, []);

    useEchoNotification(
        `App.Models.User.${userId}`,
        (payload: BroadcastPayload) => {
            setUnreadCount((c) => c + 1);
            setLastBroadcast({
                id: payload.id,
                event_type: payload.event_type,
                title: payload.title,
                body: payload.body,
                action_url: payload.action_url,
                related_id: payload.related_id,
                read_at: null,
                created_at: new Date().toISOString(),
            });

            if (soundMap[payload.event_type] !== false) {
                playSound();
            }

            if (AUTH_RESYNC_EVENTS.has(payload.event_type)) {
                router.reload({ only: ['auth'] });
            }
        },
    );

    return (
        <NotificationContext.Provider
            value={{ unreadCount, clearUnread, lastBroadcast }}
        >
            {children}
        </NotificationContext.Provider>
    );
}
