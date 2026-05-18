import { Lock, MessageSquare } from 'lucide-react';
import { useEffect, useRef } from 'react';
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
    onSend: (content: string) => void;
    // When true, render without the outer card chrome — used inside the
    // mobile bottom sheet where Sheet provides its own surface.
    bare?: boolean;
}

/**
 * Pure presentation component for a match's chat thread. Owns:
 *  - auto-scroll to bottom on new messages
 *  - empty-state copy
 *  - read-only banner when match status locks chat (Settled / ManualReview)
 *
 * Receives all data + the send callback from `useMatchChat` (called once at
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
    bare = false,
}: ChatPanelProps) {
    const scrollRef = useRef<HTMLDivElement>(null);

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

    return (
        <div
            className={cn(
                'flex h-full min-h-0 flex-col',
                !bare && 'border-border/60 bg-card/60 rounded-2xl border',
            )}
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
                        />
                    ))
                )}
            </div>

            {isReadOnly ? (
                <ReadOnlyFooter />
            ) : (
                <ChatInput onSend={onSend} disabled={isPending} />
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
