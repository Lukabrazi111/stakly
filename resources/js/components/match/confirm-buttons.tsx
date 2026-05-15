import { router } from '@inertiajs/react';
import { Trophy, X } from 'lucide-react';
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
};

interface ConfirmButtonsProps {
    matchId: number;
    myConfirmedOutcome: MatchOutcome | null;
    opponentConfirmedOutcome: MatchOutcome | null;
}

/**
 * Per-player "I won" / "I lost" buttons + confirmation Dialog.
 *
 * Behavior (locked decision 2026-05-15 — "change freely until opponent confirms"):
 *   - Selected button is gradient + disabled (no point re-confirming same outcome).
 *   - Other button stays clickable — opens Dialog → updates outcome.
 *   - Once opponent confirms, the backend resolves the match and status flips
 *     to non-Pending. Polling on the parent page picks up the change and re-
 *     renders without the confirm UI.
 */
export function ConfirmButtons({
    matchId,
    myConfirmedOutcome,
    opponentConfirmedOutcome,
}: ConfirmButtonsProps) {
    const [openOutcome, setOpenOutcome] = useState<MatchOutcome | null>(null);
    const [processing, setProcessing] = useState(false);

    const isFirstConfirmation = myConfirmedOutcome === null;

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

    const dialogTitle = isFirstConfirmation
        ? `Claim you ${OUTCOME_LABEL[openOutcome ?? 'won']}?`
        : `Change your claim to ${OUTCOME_LABEL[openOutcome ?? 'won']}?`;

    const dialogBody = isFirstConfirmation
        ? `You're claiming you ${OUTCOME_LABEL[openOutcome ?? 'won']}. You can still change your mind until your opponent confirms.`
        : `Changing your claim. You can still keep changing until your opponent confirms.`;

    return (
        <div className="space-y-4">
            <div className="grid gap-3 sm:grid-cols-2">
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
            </div>

            {opponentConfirmedOutcome !== null ? (
                <p className="text-muted-foreground text-center text-xs">
                    Your opponent claims they{' '}
                    <span className="text-foreground font-medium">
                        {OUTCOME_LABEL[opponentConfirmedOutcome].toLowerCase()}
                    </span>
                    .{myConfirmedOutcome === null && ' Your turn.'}
                </p>
            ) : (
                <p className="text-muted-foreground text-center text-xs">
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
