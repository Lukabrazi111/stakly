import { router } from '@inertiajs/react';
import { Handshake, Trophy, X } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { confirm as confirmRoute } from '@/routes/matches';
import type { MatchOutcome } from '@/types';

const OUTCOME_LABEL: Record<MatchOutcome, string> = {
    won: 'WON',
    lost: 'LOST',
    drawn: 'DRAW',
};

interface ConfirmButtonsProps {
    matchId: number;
    myConfirmedOutcome: MatchOutcome | null;
    opponentConfirmedOutcome: MatchOutcome | null;
}

/**
 * Per-player "I won" / "I lost" / "Draw" buttons + confirmation Dialog.
 *
 * Behavior (locked decision 2026-05-15 — "change freely until opponent confirms"):
 *   - Selected button is gradient + disabled (no point re-confirming same outcome).
 *   - Other buttons stay clickable — opens Dialog → updates outcome.
 *   - Once opponent confirms, the backend resolves the match and status flips
 *     to non-Pending. Polling on the parent page picks up the change and re-
 *     renders without the confirm UI.
 *
 * Resolution paths (recap):
 *   - Both claim "Draw" → settled as a draw, both stakes refunded, no fee.
 *   - Mirror Won/Lost → settled, winner takes the pot.
 *   - Any other combo (including disagreement involving Draw) → game-API arbitrates.
 */
export function ConfirmButtons({
    matchId,
    myConfirmedOutcome,
    opponentConfirmedOutcome,
}: ConfirmButtonsProps) {
    const [openOutcome, setOpenOutcome] = useState<MatchOutcome | null>(null);
    const [processing, setProcessing] = useState(false);

    const isFirstConfirmation = myConfirmedOutcome === null;
    const pendingDraw = openOutcome === 'drawn';

    const handleConfirm = (outcome: MatchOutcome) => {
        setProcessing(true);
        router.post(
            confirmRoute(matchId).url,
            { outcome },
            {
                preserveScroll: true,
                onFinish: () => {
                    setProcessing(false);
                    setOpenOutcome(null);
                },
            },
        );
    };

    // "Claim you WON?" / "Claim you LOST?" / "Claim a DRAW?" — same template
    // for won/lost; drawn gets its own grammar to avoid "Claim you DRAW?".
    const dialogTitle = pendingDraw
        ? isFirstConfirmation
            ? 'Claim a DRAW?'
            : 'Change your claim to a DRAW?'
        : isFirstConfirmation
          ? `Claim you ${OUTCOME_LABEL[openOutcome ?? 'won']}?`
          : `Change your claim to ${OUTCOME_LABEL[openOutcome ?? 'won']}?`;

    const dialogBody = pendingDraw
        ? isFirstConfirmation
            ? `You're claiming the match was a draw. Both stakes are refunded if your opponent agrees. You can still change your mind until they confirm.`
            : `Changing your claim to a draw. You can still keep changing until your opponent confirms.`
        : isFirstConfirmation
          ? `You're claiming you ${OUTCOME_LABEL[openOutcome ?? 'won']}. You can still change your mind until your opponent confirms.`
          : `Changing your claim. You can still keep changing until your opponent confirms.`;

    return (
        <div className="space-y-4">
            <div className="grid gap-3 sm:grid-cols-3">
                <ConfirmButton
                    icon={<Trophy className="size-4" />}
                    label="I won"
                    isSelected={myConfirmedOutcome === 'won'}
                    onClick={() => setOpenOutcome('won')}
                />
                <ConfirmButton
                    icon={<X className="size-4" />}
                    label="I lost"
                    isSelected={myConfirmedOutcome === 'lost'}
                    onClick={() => setOpenOutcome('lost')}
                />
                <ConfirmButton
                    icon={<Handshake className="size-4" />}
                    label="Draw"
                    isSelected={myConfirmedOutcome === 'drawn'}
                    onClick={() => setOpenOutcome('drawn')}
                />
            </div>

            {opponentConfirmedOutcome !== null ? (
                <p className="text-center text-xs text-muted-foreground">
                    {opponentConfirmedOutcome === 'drawn' ? (
                        <>
                            Your opponent claims the match was a{' '}
                            <span className="font-medium text-foreground">
                                draw
                            </span>
                            .
                        </>
                    ) : (
                        <>
                            Your opponent claims they{' '}
                            <span className="font-medium text-foreground">
                                {OUTCOME_LABEL[
                                    opponentConfirmedOutcome
                                ].toLowerCase()}
                            </span>
                            .
                        </>
                    )}
                    {myConfirmedOutcome === null && ' Your turn.'}
                </p>
            ) : (
                <p className="text-center text-xs text-muted-foreground">
                    Waiting for your opponent to confirm.
                </p>
            )}

            <Dialog
                open={openOutcome !== null}
                onOpenChange={(open) => !open && setOpenOutcome(null)}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{dialogTitle}</DialogTitle>
                        <DialogDescription>{dialogBody}</DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button
                            variant="ghost"
                            onClick={() => setOpenOutcome(null)}
                        >
                            Cancel
                        </Button>
                        <Button
                            variant="gradient"
                            onClick={() =>
                                openOutcome && handleConfirm(openOutcome)
                            }
                            disabled={processing}
                        >
                            {processing ? 'Submitting…' : 'Confirm'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}

interface ConfirmButtonProps {
    icon: ReactNode;
    label: string;
    isSelected: boolean;
    onClick: () => void;
}

function ConfirmButton({
    icon,
    label,
    isSelected,
    onClick,
}: ConfirmButtonProps) {
    return (
        <Button
            variant={isSelected ? 'gradient' : 'outline'}
            size="lg"
            disabled={isSelected}
            onClick={onClick}
            className="w-full"
        >
            {icon}
            {label}
            {isSelected && (
                <span className="ml-2 text-xs opacity-70">(your claim)</span>
            )}
        </Button>
    );
}
