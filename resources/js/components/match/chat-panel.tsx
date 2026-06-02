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
    /** Render without outer card chrome — used inside the mobile sheet. */
    bare?: boolean;
}

const ACCEPTED_MIMES = [
    'image/jpeg',
    'image/png',
    'image/webp',
    'application/pdf',
];
const MAX_FILE_SIZE_BYTES = 5 * 1024 * 1024;

/**
 * Match chat thread. Owns auto-scroll, empty state, read-only banner,
 * drag-drop image upload, and pending-file state shared with ChatInput.
 * Messages + send callback come from `useMatchChat` (called once at the
 * page level so a single Echo subscription serves both renders).
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

    // Naive auto-scroll — always pin to bottom on new message.
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

        // Filter to file drags only — text selections in chat also fire dragenter.
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
        // dragleave fires on every child boundary; only clear when truly leaving.
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
            toast.error(
                'Only JPG, PNG, WebP, or PDF files can be sent in chat.',
            );

            return;
        }

        if (file.size > MAX_FILE_SIZE_BYTES) {
            toast.error('File is larger than 5 MB.');

            return;
        }

        setPendingFile(file);
    };

    return (
        <div
            className={cn(
                'relative flex h-full min-h-0 flex-col',
                !bare && 'rounded-2xl border border-border/60 bg-card/60',
            )}
            onDragEnter={handleDragEnter}
            onDragOver={handleDragOver}
            onDragLeave={handleDragLeave}
            onDrop={handleDrop}
        >
            {!bare && (
                <header className="flex items-center gap-2 border-b border-border/60 px-4 py-3">
                    <MessageSquare className="size-4 text-muted-foreground" />
                    <h2 className="text-sm font-semibold text-foreground">
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

            {/* pointer-events-none so the drop target underneath still fires onDrop. */}
            {isDragOver && !isReadOnly && (
                <div className="pointer-events-none absolute inset-0 z-10 flex flex-col items-center justify-center gap-2 rounded-2xl border-2 border-dashed border-primary/60 bg-primary/10 backdrop-blur-sm">
                    <ImagePlus className="size-8 text-primary" />
                    <p className="text-sm font-medium text-foreground">
                        Drop file to attach
                    </p>
                    <p className="text-xs text-muted-foreground">
                        JPG, PNG, WebP, or PDF up to 5 MB
                    </p>
                </div>
            )}
        </div>
    );
}

function EmptyState() {
    return (
        <div className="flex h-full flex-col items-center justify-center gap-2 px-6 text-center text-xs text-muted-foreground">
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
        <div className="flex items-center gap-2 border-t border-border/60 px-4 py-3 text-xs text-muted-foreground">
            <Lock className="size-3.5" />
            <span>This match is settled — chat is read-only.</span>
        </div>
    );
}
