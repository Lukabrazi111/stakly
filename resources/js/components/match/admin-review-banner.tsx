import { MessageSquare, ShieldAlert } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { useT } from '@/lib/i18n';
import type { TranslationFn } from '@/lib/i18n';
import type { Match, MatchPlayer } from '@/types';

interface AdminReviewBannerProps {
    match: Match;
    viewerId: number | null;
}

interface BannerCopy {
    title: string;
    body: string;
}

export function AdminReviewBanner({ match, viewerId }: AdminReviewBannerProps) {
    const t = useT();

    if (match.status !== 'disputed' && match.status !== 'manual_review') {
        return null;
    }

    const copy = resolveCopy(match, viewerId, t);

    const handleJumpToChat = () => {
        window.dispatchEvent(new CustomEvent('stakly:focus-chat'));
    };

    return (
        <section className="mb-6 rounded-2xl border border-destructive/40 bg-destructive/5 p-5">
            <div className="flex items-start gap-3">
                <span className="inline-flex size-9 shrink-0 items-center justify-center rounded-full bg-destructive/15 text-destructive">
                    <ShieldAlert className="size-4" strokeWidth={2} />
                </span>
                <div className="min-w-0 flex-1">
                    <h2 className="font-display text-base font-semibold text-foreground">
                        {copy.title}
                    </h2>
                    <p className="mt-1 text-sm text-muted-foreground">
                        {copy.body}
                    </p>
                    <div className="mt-4">
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={handleJumpToChat}
                        >
                            <MessageSquare className="size-4" />
                            {t('Post evidence in chat')}
                        </Button>
                    </div>
                </div>
            </div>
        </section>
    );
}

function resolveCopy(
    match: Match,
    viewerId: number | null,
    t: TranslationFn,
): BannerCopy {
    const sharedBody = t(
        'A Stakly admin will review the chat and resolve this match. Post any evidence (screenshots, game URLs, PGN) below so the reviewer has the full picture. Your stake stays in escrow until resolved.',
    );

    if (match.status === 'manual_review') {
        return {
            title: t('Match flagged for admin review'),
            body: t(
                'This match was auto-flagged after the confirmation window expired without a verified game record. A Stakly admin will review the chat and resolve. Your stake stays in escrow until then.',
            ),
        };
    }

    const opener = resolveOpener(match);
    const viewerIsOpener = opener !== null && opener.id === viewerId;

    if (viewerIsOpener) {
        return {
            title: t('You reported a problem'),
            body: sharedBody,
        };
    }

    const openerName = opener?.name ?? t('Your opponent');

    return {
        title: t(':name reported a problem', { name: openerName }),
        body: sharedBody,
    };
}

function resolveOpener(match: Match): MatchPlayer | null {
    const openerId = match.dispute.opened_by_id;

    if (openerId === null) {
        return null;
    }

    if (openerId === match.creator.id) {
        return match.creator;
    }

    if (openerId === match.taker.id) {
        return match.taker;
    }

    return null;
}
