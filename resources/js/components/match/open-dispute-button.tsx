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
 * Report-a-problem escape hatch — escalates the match to game-API
 * resolution. Rendered subordinate to the I-won / I-lost / Draw buttons
 * (small inline link, not a primary action) since the cooperative
 * confirm path is the intended default.
 *
 * Visible throughout `Pending` — covers symmetric scenarios: "we disagree
 * on the outcome," "my opponent ghosted before play," "I think they
 * cheated." Spurious reports are bounded by `ResolveDisputeAction` → API
 * search → `Unknown` confidence → `ManualReview` (admin reviews, no money
 * moves until they decide).
 *
 * Confirmation Dialog uses sharpened language ("An admin will review and
 * decide who gets the pot") since the user is past the soft-prompt point.
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
                            This escalates the match to the game API for
                            resolution. The API result is final — the pot
                            will be paid to the winner immediately. If the
                            API can't determine a winner, an admin will
                            review and decide who gets the pot.
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
