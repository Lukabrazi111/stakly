import type { RequestPayload } from '@inertiajs/core';
import { router } from '@inertiajs/react';
import { useEcho } from '@laravel/echo-react';
import { useCallback, useRef, useState } from 'react';
import { toast } from 'sonner';
import { store as sendMessageRoute } from '@/routes/matches/messages';
import type { ChatMessage } from '@/types';

/**
 * State + send for a match chat. Listens for `.message.sent` on
 * `private-match.{id}` (dot prefix → `broadcastAs()` name).
 *
 * Optimistic UI: `send` appends a pending bubble with a client-generated
 * `correlation_id`; the POST carries it, the broadcast echoes it back, the
 * bubble is replaced in place on arrival. Opponent messages have no
 * matching correlation_id and append normally.
 *
 * `preserveState` + `preserveScroll` keep the panel mounted; Echo, not
 * the refreshed prop, is the authority for new messages.
 */
export function useMatchChat(
    matchId: number,
    initial: ChatMessage[],
    viewerId: number | null,
) {
    const [messages, setMessages] = useState<ChatMessage[]>(initial);
    const [isPending, setIsPending] = useState(false);
    const [uploadProgress, setUploadProgress] = useState<number | null>(null);

    // File refs survive retries. Kept out of state — File doesn't drive renders.
    const pendingFilesRef = useRef<Map<string, File>>(new Map());

    useEcho<ChatMessage>(`match.${matchId}`, '.message.sent', (payload) => {
        setMessages((prev) => {
            // Correlation_id matches a still-pending bubble — replace in place
            // so it doesn't reorder/flicker; revoke blob URL + drop File ref.
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

            // Replace by server id — the queued link-preview fetcher
            // re-broadcasts the same id with populated `attachments` once
            // OG metadata lands. Also covers strict-mode double-subscribe.
            const existingIdx = prev.findIndex((m) => m.id === payload.id);

            if (existingIdx !== -1) {
                const next = [...prev];
                next[existingIdx] = payload;

                return next;
            }

            // Append — opponent message, or a sender broadcast without a
            // correlation_id (e.g. lifecycle system message).
            return [...prev, payload];
        });
    });

    const performSend = useCallback(
        (content: string, file: File | null, correlationId: string) => {
            setIsPending(true);
            setUploadProgress(file ? 0 : null);

            // RequestPayload — `Record<string, unknown>` is wider than
            // Inertia's FormDataConvertible union and TS rejects it.
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
                              // PDFs don't get an inline preview — the bubble
                              // renders a file-icon tile from `mime` instead.
                              preview_url: file.type.startsWith('image/')
                                  ? URL.createObjectURL(file)
                                  : null,
                              size: file.size,
                              mime: file.type,
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
