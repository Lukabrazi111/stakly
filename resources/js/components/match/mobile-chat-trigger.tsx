import { MessageSquare, X } from 'lucide-react';
import { AnimatePresence, motion } from 'motion/react';
import { useEffect, useState } from 'react';
import { ChatPanel } from '@/components/match/chat-panel';
import { Button } from '@/components/ui/button';
import { useIsMobile } from '@/hooks/use-mobile';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { ChatMessage, MatchPlayer } from '@/types';

interface MobileChatTriggerProps {
    messages: ChatMessage[];
    viewerId: number;
    creator: MatchPlayer;
    taker: MatchPlayer;
    /** M34 — lobby roster (supersedes creator+taker for sender lookup). */
    participants?: MatchPlayer[];
    isReadOnly: boolean;
    isPending: boolean;
    onSend: (content: string, file: File | null) => void;
    onRetry: (correlationId: string) => void;
    onDismiss: (correlationId: string) => void;
    uploadProgress: number | null;
    /**
     * Override the default `lg:hidden` visibility. Team-play lobby passes
     * an empty string so the FAB stays visible at every viewport — the
     * 4-block center column replaced the desktop chat aside there.
     */
    containerClassName?: string;
}

/**
 * Floating chat — FAB pinned bottom-right, click to expand a compact
 * 380×480 chat card anchored to the same corner. No full-screen Sheet:
 * the lobby layout's center column already dominates the page, so the
 * chat needs to feel like a peripheral surface, not a takeover.
 *
 * Unread = `messages.length` delta since last open — no per-message read
 * state. Banner-driven focus event (`stakly:focus-chat`) auto-pops the
 * card open + re-fires once the inner input is mounted.
 */
export function MobileChatTrigger({
    messages,
    viewerId,
    creator,
    taker,
    participants,
    isReadOnly,
    isPending,
    onSend,
    onRetry,
    onDismiss,
    uploadProgress,
    containerClassName,
}: MobileChatTriggerProps) {
    const t = useT();
    const [open, setOpen] = useState(false);
    const [seenCount, setSeenCount] = useState(messages.length);
    const isMobile = useIsMobile();

    const setOpenState = (next: boolean) => {
        setOpen(next);

        if (next) {
            setSeenCount(messages.length);
        }
    };

    useEffect(() => {
        if (!isMobile) {
            return;
        }

        const handler = () => {
            if (open) {
                return;
            }

            setOpenState(true);

            window.setTimeout(() => {
                window.dispatchEvent(new CustomEvent('stakly:focus-chat'));
            }, 250);
        };

        window.addEventListener('stakly:focus-chat', handler);

        return () => window.removeEventListener('stakly:focus-chat', handler);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [isMobile, open, messages.length]);

    const unreadCount = open ? 0 : Math.max(0, messages.length - seenCount);

    return (
        <div className={containerClassName ?? 'lg:hidden'}>
            <div className="fixed right-4 bottom-4 z-40 flex flex-col items-end gap-3">
                <AnimatePresence>
                    {open && (
                        <motion.div
                            key="chat-card"
                            initial={{
                                opacity: 0,
                                y: 20,
                                scale: 0.95,
                            }}
                            animate={{ opacity: 1, y: 0, scale: 1 }}
                            exit={{ opacity: 0, y: 20, scale: 0.95 }}
                            transition={{
                                type: 'spring',
                                damping: 24,
                                stiffness: 280,
                            }}
                            style={{ transformOrigin: 'bottom right' }}
                            className="flex h-[min(520px,80vh)] w-[calc(100vw-2rem)] flex-col overflow-hidden rounded-2xl border border-border/60 bg-card shadow-2xl sm:w-[380px]"
                            role="dialog"
                            aria-label={t('Match chat')}
                        >
                            <header className="flex items-center justify-between gap-3 border-b border-border/60 bg-card/95 px-4 py-3">
                                <div className="min-w-0">
                                    <div className="font-display text-sm font-semibold text-foreground">
                                        {t('Match chat')}
                                    </div>
                                    <div className="truncate text-[11px] text-muted-foreground">
                                        {t(
                                            'Messages are part of the dispute record.',
                                        )}
                                    </div>
                                </div>
                                <button
                                    type="button"
                                    onClick={() => setOpenState(false)}
                                    aria-label={t('Close chat')}
                                    className="inline-flex size-7 shrink-0 cursor-pointer items-center justify-center rounded-full text-muted-foreground transition-colors hover:bg-primary/10 hover:text-foreground"
                                >
                                    <X className="size-4" aria-hidden="true" />
                                </button>
                            </header>

                            <div className="min-h-0 flex-1">
                                <ChatPanel
                                    messages={messages}
                                    viewerId={viewerId}
                                    creator={creator}
                                    taker={taker}
                                    participants={participants}
                                    isReadOnly={isReadOnly}
                                    isPending={isPending}
                                    onSend={onSend}
                                    onRetry={onRetry}
                                    onDismiss={onDismiss}
                                    uploadProgress={uploadProgress}
                                    bare
                                />
                            </div>
                        </motion.div>
                    )}
                </AnimatePresence>

                <Button
                    type="button"
                    variant="gradient"
                    size="pill"
                    onClick={() => setOpenState(!open)}
                    aria-label={
                        open
                            ? t('Close chat')
                            : unreadCount > 0
                              ? t('Open match chat — :count unread', {
                                    count: unreadCount,
                                })
                              : t('Open match chat')
                    }
                    className={cn(
                        'shadow-lg transition-transform',
                        open && 'rotate-0',
                    )}
                >
                    {open ? (
                        <X className="size-4" aria-hidden="true" />
                    ) : (
                        <MessageSquare className="size-4" aria-hidden="true" />
                    )}
                    <span>{open ? t('Close') : t('Chat')}</span>
                    {!open && unreadCount > 0 && (
                        <span
                            aria-hidden
                            className="ml-1 inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-background px-1.5 text-xs font-semibold text-primary"
                        >
                            {unreadCount > 99 ? '99+' : unreadCount}
                        </span>
                    )}
                </Button>
            </div>
        </div>
    );
}
