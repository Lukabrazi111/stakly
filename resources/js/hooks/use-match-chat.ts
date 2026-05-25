import type { RequestPayload } from '@inertiajs/core';
import { router } from '@inertiajs/react';
import { useEcho } from '@laravel/echo-react';
import { useCallback, useRef, useState } from 'react';
import { toast } from 'sonner';
import { store as sendMessageRoute } from '@/routes/matches/messages';
import type { ChatMessage } from '@/types';

/**
 * State + send for a single match's chat thread.
 *
 * Subscribes to the `private-match.{id}` channel via `useEcho` and listens
 * for `.message.sent` events (the dot prefix tells Echo to use the
 * `broadcastAs()` name on `MessageSent` rather than the auto-derived class
 * path).
 *
 * Optimistic UI: `send` immediately appends a `pending: true` bubble to
 * local state with a client-generated `correlation_id`. The POST carries
 * the same id; the broadcast echoes it back. On broadcast arrival, the
 * pending bubble is replaced in place (preserving order) by the server's
 * version. On error, the pending bubble flips to `failed: true` and renders
 * retry / dismiss controls. Messages from the OPPONENT don't carry a
 * matching correlation id, so they append normally.
 *
 * For attachments, the original `File` is held in a ref until the broadcast
 * confirms or the user dismisses — needed to re-POST on retry without
 * asking the user to re-pick the file.
 *
 * `preserveState` + `preserveScroll` keep the chat panel mounted and
 * pinned. The server returns a `back()` redirect that triggers a partial
 * reload — we don't read the refreshed `messages` prop because the Echo
 * broadcast is the authority for new messages.
 */
export function useMatchChat(
    matchId: number,
    initial: ChatMessage[],
    viewerId: number | null,
) {
    const [messages, setMessages] = useState<ChatMessage[]>(initial);
    const [isPending, setIsPending] = useState(false);
    const [uploadProgress, setUploadProgress] = useState<number | null>(null);

    // File references survive across retries. Cleared when the broadcast
    // confirms the corresponding correlation_id, or when the user dismisses
    // a failed bubble. Kept out of React state because the File doesn't
    // need to drive renders — only the preview URL does.
    const pendingFilesRef = useRef<Map<string, File>>(new Map());

    useEcho<ChatMessage>(`match.${matchId}`, '.message.sent', (payload) => {
        setMessages((prev) => {
            // 1) Optimistic replacement: payload's correlation_id matches
            //    a still-pending local bubble. Replace it in place so the
            //    bubble doesn't reorder or flicker. Revoke the blob URL +
            //    drop the held File reference now that the real
            //    attachments URL has landed.
            if (payload.correlation_id) {
                const idx = prev.findIndex(
                    (m) =>
                        m.correlation_id === payload.correlation_id &&
                        m.pending,
                );

                if (idx !== -1) {
                    const old = prev[idx];

                    if (old.optimistic_file?.preview_url) {
                        URL.revokeObjectURL(old.optimistic_file.preview_url);
                    }

                    pendingFilesRef.current.delete(payload.correlation_id);

                    const next = [...prev];
                    next[idx] = payload;

                    return next;
                }
            }

            // 2) Replace by server id — the queued link-preview
            //    fetcher re-broadcasts the same message id with
            //    populated `attachments` once OG metadata lands. The
            //    bubble updates in place (no scroll, no reorder).
            //    Also covers a redundant broadcast arriving twice
            //    (e.g. dev StrictMode double-subscribe) — replacing
            //    with identical payload is a no-op render.
            const existingIdx = prev.findIndex((m) => m.id === payload.id);

            if (existingIdx !== -1) {
                const next = [...prev];
                next[existingIdx] = payload;

                return next;
            }

            // 3) Append — normal new message from the opponent, or a
            //    sender broadcast without a correlation_id (e.g. system
            //    message produced by a lifecycle Action).
            return [...prev, payload];
        });
    });

    const performSend = useCallback(
        (content: string, file: File | null, correlationId: string) => {
            setIsPending(true);
            setUploadProgress(file ? 0 : null);

            // Typed as Inertia's RequestPayload so `router.post` accepts it
            // without a cast. `Record<string, unknown>` (the previous
            // annotation) is wider than the FormDataConvertible union that
            // RequestPayload allows, and TS rightly rejects it.
            const payload: RequestPayload = {
                correlation_id: correlationId,
            };

            if (content.length > 0) {
                payload.content = content;
            }

            if (file) {
                payload.file = file;
            }

            router.post(sendMessageRoute(matchId).url, payload, {
                preserveState: true,
                preserveScroll: true,
                forceFormData: Boolean(file),
                onProgress: (event) => {
                    if (event && typeof event.percentage === 'number') {
                        setUploadProgress(event.percentage);
                    }
                },
                onError: (errors) => {
                    const message =
                        errors.content ??
                        errors.file ??
                        'Could not send. Please try again.';
                    toast.error(message);

                    // Flip the matching pending bubble to failed so the user
                    // sees inline retry/dismiss controls instead of just a
                    // toast. The bubble stays in place — losing it silently
                    // is worse than leaving a marked failure.
                    setMessages((prev) =>
                        prev.map((m) =>
                            m.correlation_id === correlationId
                                ? { ...m, pending: false, failed: true }
                                : m,
                        ),
                    );
                },
                onFinish: () => {
                    setIsPending(false);
                    setUploadProgress(null);
                },
            });
        },
        [matchId],
    );

    const send = useCallback(
        (content: string, file: File | null = null) => {
            const correlationId = crypto.randomUUID();

            if (file) {
                pendingFilesRef.current.set(correlationId, file);
            }

            // Inject the optimistic bubble. Negative id so it can't collide
            // with a server-assigned positive id while we wait for the
            // broadcast. The bubble is replaced on broadcast arrival (or
            // flagged on error) — the temporary id never survives that.
            if (viewerId !== null) {
                const optimistic: ChatMessage = {
                    id: -Date.now() - Math.random(),
                    match_id: matchId,
                    user_id: viewerId,
                    type: 'text',
                    content: content.length > 0 ? content : null,
                    attachments: [],
                    correlation_id: correlationId,
                    created_at: new Date().toISOString(),
                    pending: true,
                    optimistic_file: file
                        ? {
                              name: file.name,
                              preview_url: URL.createObjectURL(file),
                              size: file.size,
                          }
                        : undefined,
                };

                setMessages((prev) => [...prev, optimistic]);
            }

            performSend(content, file, correlationId);
        },
        [matchId, performSend, viewerId],
    );

    const retry = useCallback(
        (correlationId: string) => {
            const target = messages.find(
                (m) => m.correlation_id === correlationId,
            );

            if (!target) {
                return;
            }

            // Re-send with the SAME correlation_id so the broadcast still
            // matches this bubble (the user shouldn't see a duplicate).
            setMessages((prev) =>
                prev.map((m) =>
                    m.correlation_id === correlationId
                        ? { ...m, pending: true, failed: false }
                        : m,
                ),
            );

            const file = pendingFilesRef.current.get(correlationId) ?? null;
            performSend(target.content ?? '', file, correlationId);
        },
        [messages, performSend],
    );

    const dismiss = useCallback((correlationId: string) => {
        setMessages((prev) => {
            const target = prev.find((m) => m.correlation_id === correlationId);

            if (target?.optimistic_file?.preview_url) {
                URL.revokeObjectURL(target.optimistic_file.preview_url);
            }

            return prev.filter((m) => m.correlation_id !== correlationId);
        });

        pendingFilesRef.current.delete(correlationId);
    }, []);

    return { messages, send, retry, dismiss, isPending, uploadProgress };
}
