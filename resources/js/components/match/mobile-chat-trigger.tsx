import { MessageSquare } from 'lucide-react';
import { useState } from 'react';
import { ChatPanel } from '@/components/match/chat-panel';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import type { ChatMessage, MatchPlayer } from '@/types';

interface MobileChatTriggerProps {
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
}

/**
 * Mobile-only chat affordance: a floating button bottom-right of the
 * viewport with an unread badge, opening a bottom-sheet drawer containing
 * the chat panel.
 *
 * Unread tracking — when the sheet is closed, count new messages arriving
 * via Echo as "unread." Opening the sheet resets the counter. We use the
 * total `messages.length` as the marker (no per-message read state):
 * cheaper than tracking ids and good enough for "you have new chat."
 */
export function MobileChatTrigger({
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
}: MobileChatTriggerProps) {
    const [open, setOpen] = useState(false);

    // `seenCount` marks the message count at the moment the sheet was last
    // open. Updated both on open (mark new messages as seen) and on close
    // (set the baseline for future "unread" counting). While open, unread is
    // forced to 0 because the user is actively reading. Avoiding effects
    // here keeps React 19's set-state-in-effect rule happy and removes a
    // class of update-loops.
    const [seenCount, setSeenCount] = useState(messages.length);

    const handleOpenChange = (next: boolean) => {
        setOpen(next);
        setSeenCount(messages.length);
    };

    const unreadCount = open ? 0 : Math.max(0, messages.length - seenCount);

    return (
        <div className="lg:hidden">
            <Sheet open={open} onOpenChange={handleOpenChange}>
                <Button
                    type="button"
                    variant="gradient"
                    size="pill"
                    onClick={() => handleOpenChange(true)}
                    aria-label={
                        unreadCount > 0
                            ? `Open match chat — ${unreadCount} unread`
                            : 'Open match chat'
                    }
                    className="fixed right-4 bottom-4 z-40 shadow-lg"
                >
                    <MessageSquare className="size-4" />
                    <span>Chat</span>
                    {unreadCount > 0 && (
                        <span
                            aria-hidden
                            className="bg-background text-primary ml-1 inline-flex h-5 min-w-5 items-center justify-center rounded-full px-1.5 text-xs font-semibold"
                        >
                            {unreadCount > 99 ? '99+' : unreadCount}
                        </span>
                    )}
                </Button>
                <SheetContent
                    side="bottom"
                    className="flex h-[88vh] flex-col p-0"
                >
                    <SheetHeader className="border-border/60 border-b">
                        <SheetTitle>Match chat</SheetTitle>
                        <SheetDescription className="sr-only">
                            Chat with your opponent. Messages are part of the
                            dispute record.
                        </SheetDescription>
                    </SheetHeader>
                    <div className="min-h-0 flex-1">
                        <ChatPanel
                            messages={messages}
                            viewerId={viewerId}
                            creator={creator}
                            taker={taker}
                            isReadOnly={isReadOnly}
                            isPending={isPending}
                            onSend={onSend}
                            onRetry={onRetry}
                            onDismiss={onDismiss}
                            uploadProgress={uploadProgress}
                            bare
                        />
                    </div>
                </SheetContent>
            </Sheet>
        </div>
    );
}
