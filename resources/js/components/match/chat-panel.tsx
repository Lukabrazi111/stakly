import { ImagePlus, Lock, MessageSquare } from 'lucide-react';
import type { DragEvent } from 'react';
import { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import { ChatInput } from '@/components/match/chat-input';
import { ChatMessageBubble } from '@/components/match/chat-message-bubble';
import { cn } from '@/lib/utils';
import type { ChatMessage, MatchPlayer } from '@/types';

interface ChatPanelProps {
    messages: ChatMessage[];
    viewerId: number;
    creator: MatchPlayer;
    taker: MatchPlayer;
    isReadOnly: boolean;
    isPending: boolean;
    onSend: (content: string, file: File | null) => void;
    onRetry: (correlationId: string) => void;
    onDismiss: (correlationId: string) => void;
    uploadProgress: number | null;
    // When true, render without the outer card chrome — used inside the
    // mobile bottom sheet where Sheet provides its own surface.
    bare?: boolean;
}

const ACCEPTED_MIMES = ['image/jpeg', 'image/png', 'image/webp'];
const MAX_FILE_SIZE_BYTES = 5 * 1024 * 1024;

/**
 * Pure presentation component for a match's chat thread. Owns:
 *  - auto-scroll to bottom on new messages
 *  - empty-state copy
 *  - read-only banner when match status locks chat (Settled / ManualReview)
 *  - drag-and-drop image upload (Phase 3 Slice 1) on the message list area
 *  - pending-file state shared with `ChatInput` (so drag-drop and the
 *    paperclip picker both feed into the same queued attachment)
 *
 * Receives messages + the send callback from `useMatchChat` (called once at
 * the page level so a single Echo subscription serves both the desktop
 * right-rail render and the mobile sheet render of this same component).
 */
export function ChatPanel({
    messages,
    viewerId,
    creator,
    taker,
    isReadOnly,
    isPending,
    onSend,
    onRetry,
    onDismiss,
    uploadProgress,
    bare = false,
}: ChatPanelProps) {
    const scrollRef = useRef<HTMLDivElement>(null);
    const [pendingFile, setPendingFile] = useState<File | null>(null);
    const [isDragOver, setIsDragOver] = useState(false);

    // Auto-scroll on new message. Phase 2 keeps this naive: always pin to
    // the bottom. If a user is reading history when a new message arrives,
    // they get yanked down — refine with an "at-bottom" check + a "↓ N new"
    // pill if the behaviour shows up as a complaint.
    useEffect(() => {
        const el = scrollRef.current;

        if (el) {
            el.scrollTop = el.scrollHeight;
        }
    }, [messages.length]);

    const empty = messages.length === 0;

    const handleDragEnter = (e: DragEvent<HTMLDivElement>) => {
        if (isReadOnly) {
            return;
        }

        // Only react when a file is being dragged — text selections in the
        // chat trigger dragenter too, and we don't want to flicker the
        // overlay on every accidental drag.
        if (!e.dataTransfer.types.includes('Files')) {
            return;
        }

        e.preventDefault();
        setIsDragOver(true);
    };

    const handleDragOver = (e: DragEvent<HTMLDivElement>) => {
        if (isReadOnly || !e.dataTransfer.types.includes('Files')) {
            return;
        }

        e.preventDefault();
        e.dataTransfer.dropEffect = 'copy';
    };

    const handleDragLeave = (e: DragEvent<HTMLDivElement>) => {
        // dragleave fires on every child boundary — only clear the overlay
        // when the cursor actually leaves the panel.
        if (e.currentTarget.contains(e.relatedTarget as Node | null)) {
            return;
        }

        setIsDragOver(false);
    };

    const handleDrop = (e: DragEvent<HTMLDivElement>) => {
        e.preventDefault();
        setIsDragOver(false);

        if (isReadOnly) {
            return;
        }

        const file = e.dataTransfer.files?.[0];

        if (!file) {
            return;
        }

        if (!ACCEPTED_MIMES.includes(file.type)) {
            toast.error('Only JPEG, PNG, or WebP images can be sent in chat.');

            return;
        }

        if (file.size > MAX_FILE_SIZE_BYTES) {
            toast.error('Image is larger than 5 MB.');

            return;
        }

        setPendingFile(file);
    };

    return (
        <div
            className={cn(
                'relative flex h-full min-h-0 flex-col',
                !bare && 'border-border/60 bg-card/60 rounded-2xl border',
            )}
            onDragEnter={handleDragEnter}
            onDragOver={handleDragOver}
            onDragLeave={handleDragLeave}
            onDrop={handleDrop}
        >
            {!bare && (
                <header className="border-border/60 flex items-center gap-2 border-b px-4 py-3">
                    <MessageSquare className="text-muted-foreground size-4" />
                    <h2 className="text-foreground text-sm font-semibold">
                        Match chat
                    </h2>
                </header>
            )}

            <div
                ref={scrollRef}
                className="flex-1 space-y-3 overflow-y-auto px-3 py-4"
                role="log"
                aria-live="polite"
                aria-label="Match chat messages"
            >
                {empty ? (
                    <EmptyState />
                ) : (
                    messages.map((message) => (
                        <ChatMessageBubble
                            key={message.id}
                            message={message}
                            viewerId={viewerId}
                            creator={creator}
                            taker={taker}
                            onRetry={onRetry}
                            onDismiss={onDismiss}
                        />
                    ))
                )}
            </div>

            {isReadOnly ? (
                <ReadOnlyFooter />
            ) : (
                <ChatInput
                    onSend={onSend}
                    disabled={isPending}
                    file={pendingFile}
                    onFileChange={setPendingFile}
                    uploadProgress={uploadProgress}
                />
            )}

            {/* Drag overlay — only when the user drags a file onto the panel.
                Pointer-events-none so the drop target underneath still fires
                onDrop; the overlay is purely visual. */}
            {isDragOver && !isReadOnly && (
                <div className="border-primary/60 bg-primary/10 pointer-events-none absolute inset-0 z-10 flex flex-col items-center justify-center gap-2 rounded-2xl border-2 border-dashed backdrop-blur-sm">
                    <ImagePlus className="text-primary size-8" />
                    <p className="text-foreground text-sm font-medium">
                        Drop image to attach
                    </p>
                    <p className="text-muted-foreground text-xs">
                        JPEG, PNG, or WebP up to 5 MB
                    </p>
                </div>
            )}
        </div>
    );
}

function EmptyState() {
    return (
        <div className="text-muted-foreground flex h-full flex-col items-center justify-center gap-2 px-6 text-center text-xs">
            <MessageSquare className="size-6 opacity-40" />
            <p>
                No messages yet. Share your chess.com / Lichess game URL when
                the match ends.
            </p>
        </div>
    );
}

function ReadOnlyFooter() {
    return (
        <div className="border-border/60 text-muted-foreground flex items-center gap-2 border-t px-4 py-3 text-xs">
            <Lock className="size-3.5" />
            <span>This match is settled — chat is read-only.</span>
        </div>
    );
}
