import { Loader2, Megaphone, RotateCw, TriangleAlert, X } from 'lucide-react';
import { useState } from 'react';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogTitle } from '@/components/ui/dialog';
import { useInitials } from '@/hooks/use-initials';
import { cn } from '@/lib/utils';
import type { ChatImageAttachment, ChatMessage, MatchPlayer } from '@/types';

interface ChatMessageBubbleProps {
    message: ChatMessage;
    // The current viewer — used to align own vs opponent bubbles.
    viewerId: number;
    // Sender lookup: the two known participants. Resolves `user_id` to a
    // display name without embedding the user object on every message.
    creator: MatchPlayer;
    taker: MatchPlayer;
    // Optimistic-UI handlers — invoked from the failed-bubble footer.
    // No-op for server-sourced messages (they never reach a failed state).
    onRetry: (correlationId: string) => void;
    onDismiss: (correlationId: string) => void;
}

export function ChatMessageBubble({
    message,
    viewerId,
    creator,
    taker,
    onRetry,
    onDismiss,
}: ChatMessageBubbleProps) {
    if (message.type === 'system') {
        return <SystemBubble content={message.content ?? ''} />;
    }

    const isOwn = message.user_id === viewerId;
    const sender = message.user_id === creator.id ? creator : taker;

    const images = message.attachments.filter(
        (attachment): attachment is ChatImageAttachment =>
            attachment.type === 'image',
    );
    const hasContent = (message.content ?? '').length > 0;
    const isPending = Boolean(message.pending);
    const isFailed = Boolean(message.failed);
    const hasOptimisticFile = Boolean(message.optimistic_file);

    return (
        <div
            className={cn(
                'flex w-full gap-2',
                isOwn ? 'justify-end' : 'justify-start',
            )}
        >
            {!isOwn && <SenderAvatar name={sender.name} />}

            <div
                className={cn(
                    'flex max-w-[78%] flex-col gap-1',
                    isOwn ? 'items-end' : 'items-start',
                    isPending && 'opacity-70',
                )}
            >
                {/* Optimistic local-file preview takes the place of real
                    attachments while the upload is in flight or after a
                    send failure. Replaced when the broadcast lands. */}
                {hasOptimisticFile && message.optimistic_file && (
                    <OptimisticImage
                        previewUrl={message.optimistic_file.preview_url}
                        name={message.optimistic_file.name}
                        isOwn={isOwn}
                        isFailed={isFailed}
                    />
                )}

                {!hasOptimisticFile
                    && images.map((image) => (
                        <ImageAttachment
                            key={image.media_id}
                            image={image}
                            isOwn={isOwn}
                        />
                    ))}

                {hasContent && (
                    <div
                        className={cn(
                            'rounded-2xl px-3.5 py-2 text-sm leading-snug break-words whitespace-pre-wrap',
                            isOwn
                                ? 'bg-primary text-primary-foreground rounded-br-md'
                                : 'border-border/60 bg-card text-foreground rounded-bl-md border',
                            isFailed && 'border-destructive/60 border',
                        )}
                    >
                        {message.content}
                    </div>
                )}

                {isFailed && message.correlation_id ? (
                    <FailedFooter
                        onRetry={() => onRetry(message.correlation_id!)}
                        onDismiss={() => onDismiss(message.correlation_id!)}
                    />
                ) : isPending ? (
                    <PendingFooter />
                ) : (
                    <time
                        className="text-muted-foreground text-[10px] tabular-nums"
                        dateTime={message.created_at ?? undefined}
                    >
                        {formatBubbleTime(message.created_at)}
                    </time>
                )}
            </div>
        </div>
    );
}

interface ImageAttachmentProps {
    image: ChatImageAttachment;
    isOwn: boolean;
}

/**
 * Inline thumbnail bubble rendered above any caption. Click opens the
 * full-resolution original inside a centered dialog. Both src URLs point at
 * the authenticated streaming route, so the browser asks the Stakly app for
 * each fetch — no public-disk leak path.
 *
 * When the backend supplies `width` + `height`, set them on the img so the
 * browser reserves the right box before bytes arrive — no scroll-shift when
 * chat history scrolls past a run of unloaded images.
 */
function ImageAttachment({ image, isOwn }: ImageAttachmentProps) {
    const [open, setOpen] = useState(false);

    return (
        <>
            <button
                type="button"
                onClick={() => setOpen(true)}
                className={cn(
                    'border-border/60 group bg-card focus-visible:ring-primary focus-visible:ring-offset-background overflow-hidden rounded-2xl border transition-shadow hover:shadow-glow-sm focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none',
                    isOwn ? 'rounded-br-md' : 'rounded-bl-md',
                )}
                aria-label={`Open image: ${image.name}`}
            >
                <img
                    src={image.thumb_url}
                    alt={image.name}
                    width={image.width ?? undefined}
                    height={image.height ?? undefined}
                    loading="lazy"
                    className="max-h-64 max-w-[300px] object-contain"
                />
            </button>

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="max-w-4xl border-none bg-transparent p-0 shadow-none">
                    <DialogTitle className="sr-only">{image.name}</DialogTitle>
                    <img
                        src={image.url}
                        alt={image.name}
                        className="max-h-[85vh] w-full rounded-2xl object-contain"
                    />
                </DialogContent>
            </Dialog>
        </>
    );
}

interface OptimisticImageProps {
    previewUrl: string;
    name: string;
    isOwn: boolean;
    isFailed: boolean;
}

/**
 * Local-blob preview rendered while an upload is in flight (and kept
 * visible if the send failed so the user can retry without re-picking the
 * file). Not clickable into a lightbox — the original doesn't exist on the
 * server yet. Replaced by the real `ImageAttachment` render once the
 * broadcast confirms the message.
 */
function OptimisticImage({
    previewUrl,
    name,
    isOwn,
    isFailed,
}: OptimisticImageProps) {
    return (
        <div
            className={cn(
                'border-border/60 bg-card relative overflow-hidden rounded-2xl border',
                isOwn ? 'rounded-br-md' : 'rounded-bl-md',
                isFailed && 'border-destructive/60',
            )}
        >
            <img
                src={previewUrl}
                alt={name}
                className={cn(
                    'max-h-64 max-w-[300px] object-contain',
                    isFailed && 'opacity-50',
                )}
            />
            {!isFailed && (
                <div className="bg-background/60 absolute inset-0 flex items-center justify-center backdrop-blur-[1px]">
                    <Loader2 className="text-primary size-6 animate-spin" />
                </div>
            )}
        </div>
    );
}

function PendingFooter() {
    return (
        <span className="text-muted-foreground inline-flex items-center gap-1 text-[10px]">
            <Loader2 className="size-2.5 animate-spin" />
            Sending…
        </span>
    );
}

interface FailedFooterProps {
    onRetry: () => void;
    onDismiss: () => void;
}

function FailedFooter({ onRetry, onDismiss }: FailedFooterProps) {
    return (
        <div className="text-destructive inline-flex items-center gap-2 text-[11px]">
            <TriangleAlert className="size-3" />
            <span>Failed to send</span>
            <Button
                type="button"
                variant="ghost"
                size="sm"
                onClick={onRetry}
                className="text-destructive hover:bg-destructive/10 hover:text-destructive hover:[text-shadow:none] h-6 gap-1 px-2 text-[11px]"
            >
                <RotateCw className="size-3" />
                Retry
            </Button>
            <Button
                type="button"
                variant="ghost"
                size="sm"
                onClick={onDismiss}
                aria-label="Dismiss"
                className="text-muted-foreground hover:text-foreground hover:[text-shadow:none] h-6 px-1.5"
            >
                <X className="size-3" />
            </Button>
        </div>
    );
}

function SenderAvatar({ name }: { name: string }) {
    const getInitials = useInitials();

    return (
        <Avatar className="size-7 shrink-0">
            <AvatarFallback className="bg-gradient-primary text-primary-foreground text-[10px] font-semibold">
                {getInitials(name)}
            </AvatarFallback>
        </Avatar>
    );
}

function SystemBubble({ content }: { content: string }) {
    return (
        <div className="flex justify-center">
            <div className="border-border/60 bg-muted/40 text-muted-foreground inline-flex max-w-[92%] items-start gap-2 rounded-lg border px-3 py-2 text-xs">
                <Megaphone className="mt-0.5 size-3.5 shrink-0" />
                <span className="text-left">{content}</span>
            </div>
        </div>
    );
}

/**
 * Compact same-day formatter: "14:32".
 */
function formatBubbleTime(iso: string | null): string {
    if (!iso) {
        return '';
    }

    const date = new Date(iso);

    return date.toLocaleTimeString(undefined, {
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
    });
}
