import { router } from '@inertiajs/react';
import { useEcho } from '@laravel/echo-react';
import { useCallback, useState } from 'react';
import { toast } from 'sonner';
import { store as sendMessageRoute } from '@/routes/matches/messages';
import type { ChatMessage } from '@/types';

/**
 * State + send for a single match's chat thread (M8 Phase 2).
 *
 * Subscribes to the `private-match.{id}` channel via `useEcho` and listens
 * for `.message.sent` events (the dot prefix tells Echo to use the
 * `broadcastAs()` name on `MessageSent` rather than the auto-derived class
 * path). New broadcasts are appended to local state with id-based dedup,
 * so the sender's own message — which lands via this same broadcast — is
 * never duplicated.
 *
 * `send` POSTs to `/matches/{match}/messages` via Inertia. `preserveState`
 * + `preserveScroll` keep the chat panel mounted and pinned. The server
 * returns a `back()` redirect that triggers a partial reload — we don't
 * read the refreshed `messages` prop because the Echo broadcast is the
 * authority for new messages. Errors (422 / 429) surface as toasts.
 */
export function useMatchChat(matchId: number, initial: ChatMessage[]) {
    const [messages, setMessages] = useState<ChatMessage[]>(initial);
    const [isPending, setIsPending] = useState(false);

    useEcho<ChatMessage>(
        `match.${matchId}`,
        '.message.sent',
        (payload) => {
            setMessages((prev) => {
                if (prev.some((m) => m.id === payload.id)) {
                    return prev;
                }

                return [...prev, payload];
            });
        },
    );

    const send = useCallback(
        (content: string) => {
            setIsPending(true);
            router.post(
                sendMessageRoute(matchId).url,
                { content },
                {
                    preserveState: true,
                    preserveScroll: true,
                    onError: (errors) => {
                        const message =
                            errors.content
                            ?? 'Could not send. Please try again.';
                        toast.error(message);
                    },
                    onFinish: () => setIsPending(false),
                },
            );
        },
        [matchId],
    );

    return { messages, send, isPending };
}
