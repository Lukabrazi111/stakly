import { Megaphone } from 'lucide-react';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { useInitials } from '@/hooks/use-initials';
import { cn } from '@/lib/utils';
import type { ChatMessage, MatchPlayer } from '@/types';

interface ChatMessageBubbleProps {
    message: ChatMessage;
    // The current viewer — used to align own vs opponent bubbles.
    viewerId: number;
    // Sender lookup: the two known participants. Resolves `user_id` to a
    // display name without embedding the user object on every message.
    creator: MatchPlayer;
    taker: MatchPlayer;
}

export function ChatMessageBubble({
    message,
    viewerId,
    creator,
    taker,
}: ChatMessageBubbleProps) {
    if (message.type === 'system') {
        return <SystemBubble content={message.content} />;
    }

    const isOwn = message.user_id === viewerId;
    const sender = message.user_id === creator.id ? creator : taker;

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
                    'flex max-w-[78%] flex-col gap-0.5',
                    isOwn ? 'items-end' : 'items-start',
                )}
            >
                <div
                    className={cn(
                        'rounded-2xl px-3.5 py-2 text-sm leading-snug break-words whitespace-pre-wrap',
                        isOwn
                            ? 'bg-primary text-primary-foreground rounded-br-md'
                            : 'border-border/60 bg-card text-foreground rounded-bl-md border',
                    )}
                >
                    {message.content}
                </div>
                <time
                    className="text-muted-foreground text-[10px] tabular-nums"
                    dateTime={message.created_at ?? undefined}
                >
                    {formatBubbleTime(message.created_at)}
                </time>
            </div>
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
 * Compact same-day formatter: "14:32". On older days we just keep the same
 * minimal shape — Phase 2 doesn't render date separators yet; if chat
 * volume warrants it (likely Phase 3+), add a per-day header instead of
 * cluttering each bubble.
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
