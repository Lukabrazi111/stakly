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
 * Report-a-problem escape hatch — flags the match for admin review.
 * Rendered subordinate (small inline link, not a primary action) since
 * the cooperative path is the intended default.
 *
 * Visible throughout `Pending`. M12 Phase 3 — the button used to escalate
 * to game-API auto-resolution; it now flips the match to `Disputed` and
 * surfaces it in the M12 admin queue. Money stays escrowed until admin
 * decides, so spurious reports cost only review time, not funds.
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
                Report a problem
            </button>

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Report a problem?</DialogTitle>
                        <DialogDescription>
                            This flags the match for admin review. A Stakly
                            admin will read the chat and any evidence you
                            post, then decide who wins the pot (or refund
                            both stakes as a draw). Your stake stays in
                            escrow until they resolve.
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
                            {processing ? 'Reporting…' : 'Yes, report'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
