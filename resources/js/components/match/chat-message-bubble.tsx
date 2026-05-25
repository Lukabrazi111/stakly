import {
    BadgeCheck,
    Crown,
    ExternalLink,
    Link as LinkIcon,
    Loader2,
    Megaphone,
    RotateCw,
    TriangleAlert,
    X,
} from 'lucide-react';
import { useState } from 'react';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogTitle } from '@/components/ui/dialog';
import { useInitials } from '@/hooks/use-initials';
import { cn } from '@/lib/utils';
import type {
    ChatDisputePromptAttachment,
    ChatGameCardAttachment,
    ChatImageAttachment,
    ChatLinkAttachment,
    ChatMessage,
    MatchPlayer,
} from '@/types';

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
    const gameCards = message.attachments.filter(
        (attachment): attachment is ChatGameCardAttachment =>
            attachment.type === 'game_card',
    );
    const isDisputePrompt = message.attachments.some(
        (attachment): attachment is ChatDisputePromptAttachment =>
            attachment.type === 'dispute_prompt',
    );

    if (message.type === 'system') {
        return (
            <SystemBubble
                content={message.content ?? ''}
                gameCards={gameCards}
                variant={isDisputePrompt ? 'dispute_prompt' : 'default'}
            />
        );
    }

    const isOwn = message.user_id === viewerId;
    const sender = message.user_id === creator.id ? creator : taker;

    const images = message.attachments.filter(
        (attachment): attachment is ChatImageAttachment =>
            attachment.type === 'image',
    );
    const links = message.attachments.filter(
        (attachment): attachment is ChatLinkAttachment =>
            attachment.type === 'link',
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

                {!hasOptimisticFile &&
                    images.map((image) => (
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
                                ? 'rounded-br-md bg-primary text-primary-foreground'
                                : 'rounded-bl-md border border-border/60 bg-card text-foreground',
                            isFailed && 'border border-destructive/60',
                        )}
                    >
                        {message.content}
                    </div>
                )}

                {links.map((link) => (
                    <LinkCard key={link.url} link={link} isOwn={isOwn} />
                ))}

                {gameCards.map((card) => (
                    <GameCardAttachment
                        key={`${card.game_id}-${card.source}`}
                        card={card}
                        isOwn={isOwn}
                    />
                ))}

                {isFailed && message.correlation_id ? (
                    <FailedFooter
                        onRetry={() => onRetry(message.correlation_id!)}
                        onDismiss={() => onDismiss(message.correlation_id!)}
                    />
                ) : isPending ? (
                    <PendingFooter />
                ) : (
                    <time
                        className="text-[10px] text-muted-foreground tabular-nums"
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
                    'group overflow-hidden rounded-2xl border border-border/60 bg-card transition-shadow hover:shadow-glow-sm focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none',
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

interface LinkCardProps {
    link: ChatLinkAttachment;
    isOwn: boolean;
}

/**
 * OG/Twitter/oEmbed unfurl card rendered below the chat bubble's text
 * content. Whole card is one anchor so the browser handles middle-click,
 * Cmd-click, drag-to-bookmark, etc. naturally — no nested interactive
 * elements that compete for click semantics.
 *
 * The image (when present) comes from `link-images.show`, the
 * authenticated proxy route. We set `width`/`height` to the rendered
 * size (not the natural image size) because OG images are highly
 * variable and we want a fixed-aspect-ratio tile, not flex-based image
 * sizing that shifts layout on bytes-arrived.
 */
function LinkCard({ link, isOwn }: LinkCardProps) {
    const hostname =
        link.site_name ?? safeHostname(link.canonical_url ?? link.url);

    return (
        <a
            href={link.url}
            target="_blank"
            rel="noopener noreferrer nofollow"
            className={cn(
                'group flex w-full max-w-[320px] gap-3 overflow-hidden rounded-2xl border border-border/60 bg-card/80 p-2.5 transition-all duration-200 hover:border-primary/40 hover:shadow-glow-sm focus-visible:border-primary/60 focus-visible:ring-2 focus-visible:ring-primary/40 focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none',
                isOwn ? 'rounded-br-md' : 'rounded-bl-md',
            )}
        >
            {link.image_url ? (
                <img
                    src={link.image_url}
                    alt=""
                    width={64}
                    height={64}
                    loading="lazy"
                    className="size-16 shrink-0 rounded-lg bg-muted object-cover"
                />
            ) : (
                <div className="flex size-16 shrink-0 items-center justify-center rounded-lg bg-muted/60 text-muted-foreground/60">
                    <LinkIcon className="size-5" />
                </div>
            )}

            <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                <h4 className="line-clamp-2 text-sm leading-snug font-semibold text-foreground transition-colors group-hover:text-primary">
                    {link.title}
                </h4>
                {link.description && (
                    <p className="line-clamp-2 text-xs leading-snug text-muted-foreground">
                        {link.description}
                    </p>
                )}
                {hostname && (
                    <span className="mt-auto truncate pt-0.5 text-[10px] tracking-wide text-muted-foreground/70 uppercase">
                        {hostname}
                    </span>
                )}
            </div>
        </a>
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
                'relative overflow-hidden rounded-2xl border border-border/60 bg-card',
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
                <div className="absolute inset-0 flex items-center justify-center bg-background/60 backdrop-blur-[1px]">
                    <Loader2 className="size-6 animate-spin text-primary" />
                </div>
            )}
        </div>
    );
}

function PendingFooter() {
    return (
        <span className="inline-flex items-center gap-1 text-[10px] text-muted-foreground">
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
        <div className="inline-flex items-center gap-2 text-[11px] text-destructive">
            <TriangleAlert className="size-3" />
            <span>Failed to send</span>
            <Button
                type="button"
                variant="ghost"
                size="sm"
                onClick={onRetry}
                className="h-6 gap-1 px-2 text-[11px] text-destructive hover:bg-destructive/10 hover:text-destructive hover:[text-shadow:none]"
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
                className="h-6 px-1.5 text-muted-foreground hover:text-foreground hover:[text-shadow:none]"
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
            <AvatarFallback className="bg-gradient-primary text-[10px] font-semibold text-primary-foreground">
                {getInitials(name)}
            </AvatarFallback>
        </Avatar>
    );
}

interface SystemBubbleProps {
    content: string;
    gameCards: ChatGameCardAttachment[];
    // `dispute_prompt` swaps the muted lifecycle styling for a warning
    // variant — the message is a call-to-action ("submit evidence") that
    // should stand out from neutral lifecycle narration.
    variant?: 'default' | 'dispute_prompt';
}

function SystemBubble({
    content,
    gameCards,
    variant = 'default',
}: SystemBubbleProps) {
    const isPrompt = variant === 'dispute_prompt';

    return (
        <div className="flex flex-col items-center gap-2">
            <div
                className={cn(
                    'inline-flex max-w-[92%] items-start gap-2 rounded-lg border px-3 py-2 text-xs',
                    isPrompt
                        ? 'border-warning/40 bg-warning/10 text-warning'
                        : 'border-border/60 bg-muted/40 text-muted-foreground',
                )}
            >
                {isPrompt ? (
                    <TriangleAlert className="mt-0.5 size-3.5 shrink-0" />
                ) : (
                    <Megaphone className="mt-0.5 size-3.5 shrink-0" />
                )}
                <span className="text-left">{content}</span>
            </div>
            {gameCards.map((card) => (
                <GameCardAttachment
                    key={`${card.game_id}-${card.source}`}
                    card={card}
                    // System cards aren't tied to a sender so isOwn doesn't
                    // apply — false renders the symmetric (not own-aligned)
                    // corner radius.
                    isOwn={false}
                />
            ))}
        </div>
    );
}

interface GameCardAttachmentProps {
    card: ChatGameCardAttachment;
    isOwn: boolean;
}

/**
 * Phase 4 verified-game evidence card. Same chat-card visual family as
 * `LinkCard` — pill border, Stakly-skinned hover glow — with a chess
 * provenance tile on the left and structured game metadata on the right.
 *
 * The verified badge is colour + icon (not colour alone) per the
 * accessibility rule — colour-blind users still see the BadgeCheck
 * affordance.
 */
function GameCardAttachment({ card, isOwn }: GameCardAttachmentProps) {
    const winnerLabel = describeWinner(card);
    const speedLabel = card.speed ? capitalize(card.speed) : null;

    return (
        <a
            href={card.url}
            target="_blank"
            rel="noopener noreferrer"
            aria-label={`Open Lichess game ${card.game_id}`}
            className={cn(
                'group flex w-full max-w-[320px] cursor-pointer flex-col gap-2 overflow-hidden rounded-2xl border border-border/60 bg-card/80 p-3 transition-all duration-200 hover:border-primary/40 hover:shadow-glow-sm focus-visible:border-primary/60 focus-visible:ring-2 focus-visible:ring-primary/40 focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none',
                isOwn ? 'rounded-br-md' : 'rounded-bl-md',
            )}
        >
            <div className="flex items-center justify-between gap-2">
                <div className="flex min-w-0 items-center gap-2">
                    <span className="inline-flex size-8 shrink-0 items-center justify-center rounded-lg bg-gradient-to-br from-primary/40 to-accent/40 text-foreground">
                        <Crown className="size-4" strokeWidth={1.75} />
                    </span>
                    <div className="flex min-w-0 flex-col">
                        <span className="text-xs font-semibold tracking-tight text-foreground">
                            Lichess game
                        </span>
                        <span className="truncate text-[10px] tracking-wide text-muted-foreground/80 uppercase">
                            {[speedLabel, card.rated ? 'Rated' : 'Casual']
                                .filter(Boolean)
                                .join(' · ')}
                        </span>
                    </div>
                </div>
                {card.verified ? (
                    <span className="inline-flex items-center gap-1 rounded-full bg-success/15 px-2 py-0.5 text-[10px] font-semibold tracking-wide text-success uppercase">
                        <BadgeCheck className="size-3" strokeWidth={2} />
                        Verified
                    </span>
                ) : (
                    <span className="inline-flex items-center gap-1 rounded-full border border-border/60 px-2 py-0.5 text-[10px] font-semibold tracking-wide text-muted-foreground uppercase">
                        Unverified
                    </span>
                )}
            </div>

            {(card.white_username || card.black_username) && (
                <div className="flex items-center gap-2 text-xs">
                    <PlayerBadge
                        username={card.white_username}
                        color="white"
                        isWinner={card.winner_color === 'white'}
                    />
                    <span className="text-[10px] tracking-wide text-muted-foreground/60 uppercase">
                        vs
                    </span>
                    <PlayerBadge
                        username={card.black_username}
                        color="black"
                        isWinner={card.winner_color === 'black'}
                    />
                </div>
            )}

            {winnerLabel && (
                <p className="text-xs leading-snug text-foreground">
                    {winnerLabel}
                </p>
            )}

            <div className="mt-auto inline-flex items-center gap-1 text-[10px] tracking-wide text-muted-foreground/70 uppercase transition-colors group-hover:text-primary">
                lichess.org
                <ExternalLink className="size-3" />
            </div>
        </a>
    );
}

interface PlayerBadgeProps {
    username: string | null;
    color: 'white' | 'black';
    isWinner: boolean;
}

function PlayerBadge({ username, color, isWinner }: PlayerBadgeProps) {
    return (
        <span
            className={cn(
                'inline-flex min-w-0 items-center gap-1.5 rounded-full px-2 py-0.5 text-[11px] font-medium',
                isWinner
                    ? 'bg-success/15 text-success'
                    : 'bg-muted/60 text-muted-foreground',
            )}
        >
            <span
                aria-hidden
                className={cn(
                    'size-2 shrink-0 rounded-full',
                    color === 'white'
                        ? 'border border-border/80 bg-white'
                        : 'bg-foreground/80',
                )}
            />
            <span className="truncate">{username ?? '—'}</span>
        </span>
    );
}

/**
 * Map raw status + winner to human chat copy. Handles BOTH Lichess and
 * chess.com vocabularies — they use different status strings for the
 * same outcomes:
 *   Lichess:   mate / resign / outoftime / timeout / cheat / draw / stalemate / aborted
 *   chess.com: checkmated / resigned / timeout / abandoned / agreed / repetition / stalemate / etc.
 *
 * For chess.com, `status` is set to the LOSER's per-side `result` string
 * by `ChessComGameClient::parseGame`. So a checkmate-win game has
 * status='checkmated', a resignation has status='resigned', etc.
 */
function describeWinner(card: ChatGameCardAttachment): string | null {
    const status = card.status;
    const winner = card.winner_username;

    if (winner && status) {
        const reason: Record<string, string> = {
            // Lichess vocabulary
            mate: 'by checkmate',
            resign: 'by resignation',
            outoftime: 'on time',
            timeout: 'on time',
            cheat: 'by cheat report',
            // chess.com vocabulary (loser's result string)
            checkmated: 'by checkmate',
            resigned: 'by resignation',
            abandoned: 'by abandonment',
            lose: '',
        };

        const text = reason[status];

        if (text !== undefined) {
            return text === '' ? `${winner} won.` : `${winner} won ${text}.`;
        }

        return `${winner} won.`;
    }

    // Draw vocabulary — covers both providers.
    const drawStatuses: Record<string, string> = {
        draw: 'Drawn.',
        stalemate: 'Drawn by stalemate.',
        agreed: 'Drawn by agreement.',
        repetition: 'Drawn by repetition.',
        insufficient: 'Drawn — insufficient material.',
        '50move': 'Drawn by 50-move rule.',
        timevsinsufficient: 'Drawn — time vs insufficient material.',
    };

    if (status && status in drawStatuses) {
        return drawStatuses[status];
    }

    if (status === 'aborted') {
        return 'Game aborted.';
    }

    return null;
}

function capitalize(value: string): string {
    if (value.length === 0) {
        return value;
    }

    return value.charAt(0).toUpperCase() + value.slice(1);
}

/**
 * Pull the hostname off a URL string for the link-card footer. Falls
 * back to null on malformed input rather than throwing — a broken URL
 * shouldn't break the bubble render.
 */
function safeHostname(url: string | null): string | null {
    if (!url) {
        return null;
    }

    try {
        return new URL(url).hostname;
    } catch {
        return null;
    }
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
