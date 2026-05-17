import { router } from '@inertiajs/react';
import { AlertTriangle } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { openDispute as openDisputeRoute } from '@/routes/matches';

interface OpenDisputeButtonProps {
    matchId: number;
}

/**
 * Escape hatch from the player-confirm flow — escalates to game-API
 * resolution. Rendered subordinate to the I-won / I-lost / Draw buttons
 * (small inline link, not a primary action) since the cooperative path
 * is the intended default.
 *
 * Parent gates this on EITHER player having confirmed an outcome. When
 * neither has claimed, there's nothing to dispute. Once anyone claims,
 * the button is available — including to a viewer who hasn't claimed
 * yet, so a player can challenge a bad-faith claim from their opponent
 * without first locking themselves into one.
 *
 * Confirmation Dialog matches the pattern used by `ConfirmButtons` so the
 * destructive copy stays consistent across the match page.
 */
export function OpenDisputeButton({ matchId }: OpenDisputeButtonProps) {
    const [open, setOpen] = useState(false);
    const [processing, setProcessing] = useState(false);

    const handleOpen = () => {
        setProcessing(true);
        router.post(
            openDisputeRoute(matchId).url,
            {},
            {
                preserveScroll: true,
                onFinish: () => {
                    setProcessing(false);
                    setOpen(false);
                },
            },
        );
    };

    return (
        <>
            <button
                type="button"
                onClick={() => setOpen(true)}
                className="inline-flex cursor-pointer items-center gap-1.5 rounded-sm text-xs text-muted-foreground underline-offset-4 transition-colors hover:text-warning hover:underline focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none"
            >
                <AlertTriangle className="size-3.5" aria-hidden="true" />
                Can't agree? Open a dispute
            </button>

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Open a dispute?</DialogTitle>
                        <DialogDescription>
                            This resolves the match using the official game API
                            instead of waiting for both players to agree. The
                            API result is final — the pot will be paid out to
                            the winner immediately. If the API can't determine a
                            winner, the match goes to admin review and your
                            stake stays in escrow.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button
                            variant="ghost"
                            onClick={() => setOpen(false)}
                            disabled={processing}
                        >
                            Cancel
                        </Button>
                        <Button
                            variant="destructive"
                            onClick={handleOpen}
                            disabled={processing}
                        >
                            {processing ? 'Opening…' : 'Open dispute'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
