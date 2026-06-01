import { useCallback, useEffect, useRef } from 'react';
import type { NotificationSoundPriority } from '@/types/notification';

const SOUND_URLS: Record<NotificationSoundPriority, string | null> = {
    urgent: '/sounds/urgent.mp3',
    soft: '/sounds/soft.mp3',
    none: null,
};

const CHANNEL_NAME = 'stakly:notification-sound';

// Wide enough to absorb BroadcastChannel delivery jitter across tabs, narrow
// enough that a legitimately-spaced second broadcast still plays.
const DEDUP_WINDOW_MS = 500;

export function useNotificationSound() {
    const channelRef = useRef<BroadcastChannel | null>(null);
    const lastClaimAtRef = useRef<number>(0);

    useEffect(() => {
        if (typeof BroadcastChannel === 'undefined') {
            return;
        }

        const channel = new BroadcastChannel(CHANNEL_NAME);
        channel.addEventListener('message', (event: MessageEvent) => {
            const data = event.data as { type?: string; at?: number } | null;

            if (data?.type === 'claim' && typeof data.at === 'number') {
                lastClaimAtRef.current = Math.max(
                    lastClaimAtRef.current,
                    data.at,
                );
            }
        });
        channelRef.current = channel;

        return () => {
            channel.close();
            channelRef.current = null;
        };
    }, []);

    return useCallback((priority: NotificationSoundPriority) => {
        const url = SOUND_URLS[priority];

        if (!url) {
            return;
        }

        const now = Date.now();

        if (now - lastClaimAtRef.current < DEDUP_WINDOW_MS) {
            return;
        }

        lastClaimAtRef.current = now;
        channelRef.current?.postMessage({ type: 'claim', at: now });

        const audio = new Audio(url);
        audio.volume = 0.5;
        audio.play().catch(() => {
            // Autoplay blocked (no user gesture yet) or file missing.
        });
    }, []);
}
