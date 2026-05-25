import { router, usePage } from '@inertiajs/react';
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
import { update as activeModeUpdate } from '@/routes/active-mode';

interface Props {
    /**
     * Number of currently-Open listings the user has. Drives the contextual
     * hint shown when Active Mode is on but there's nothing on the board —
     * surfaces that the toggle is honored but inert until the user posts
     * something.
     */
    listingsCount: number;
}

/**
 * Bybit-style global "online/offline" toggle for the user's listings. Lives
 * only on the /listings/mine page header. Visual: status label + sliding
 * switch (custom-styled HTML button — shadcn doesn't ship a Switch primitive
 * yet and a single use site doesn't earn pulling one in).
 *
 * Asymmetric-risk handling:
 *   - Active → Inactive: direct POST (safe direction — just hides everything).
 *   - Inactive → Active: confirmation dialog (reactivating can trigger
 *     an immediate match within seconds, so the user gets one explicit
 *     "ready to play?" gate).
 *
 * Active Mode is *intent*, not a derived state from inventory — we never
 * auto-flip when the user hits 0 listings. Instead, the description swaps
 * to a contextual hint so the user knows their toggle is honored but inert
 * until they post.
 */
export function ActiveModeToggle({ listingsCount }: Props) {
    const { auth } = usePage().props;
    const active = auth.user?.is_active_mode ?? true;
    const hasNoListings = listingsCount === 0;

    const [dialogOpen, setDialogOpen] = useState(false);
    const [processing, setProcessing] = useState(false);

    const handleToggleClick = () => {
        if (active) {
            // Going inactive — safe direction, no dialog.
            if (processing) {
                return;
            }

            setProcessing(true);
            router.post(
                activeModeUpdate().url,
                { active: false },
                {
                    preserveScroll: true,
                    onFinish: () => setProcessing(false),
                },
            );

            return;
        }

        // Going active — confirm first.
        setDialogOpen(true);
    };

    const handleConfirmActivate = () => {
        setProcessing(true);
        router.post(
            activeModeUpdate().url,
            { active: true },
            {
                preserveScroll: true,
                onFinish: () => {
                    setProcessing(false);
                    setDialogOpen(false);
                },
            },
        );
    };

    return (
        <>
            <div className="flex items-center justify-between gap-3">
                {/* Mobile: labels left-aligned (text reads left-to-right
                    naturally), switch on the far right via `justify-between`.
                    Desktop: parent forces intrinsic width so `justify-between`
                    is a no-op; labels right-align (items-end) to sit flush
                    against the switch for a tight, compact group. */}
                <div className="flex flex-col items-start sm:items-end">
                    <span className="text-sm font-medium text-foreground">
                        {active ? 'Active Mode' : 'Inactive Mode'}
                    </span>
                    <span
                        className={`text-xs ${active && hasNoListings ? 'text-warning' : 'text-muted-foreground'}`}
                    >
                        {active
                            ? hasNoListings
                                ? 'Post a listing to appear on the board'
                                : 'Listings visible to the marketplace'
                            : 'Listings hidden from the marketplace'}
                    </span>
                </div>
                <button
                    type="button"
                    role="switch"
                    aria-checked={active}
                    aria-label={
                        active
                            ? 'Switch to Inactive Mode'
                            : 'Switch to Active Mode'
                    }
                    onClick={handleToggleClick}
                    disabled={processing}
                    className={`relative inline-flex h-7 w-12 shrink-0 cursor-pointer items-center rounded-full transition-colors duration-200 ease-out focus-visible:ring-2 focus-visible:ring-primary/25 focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50 ${
                        active
                            ? 'bg-success shadow-[inset_0_0_0_1px_var(--color-success)]'
                            : 'bg-muted shadow-[inset_0_0_0_1px_var(--color-border)]'
                    }`}
                >
                    <span
                        className={`inline-block size-5 transform rounded-full bg-white shadow-md transition-transform duration-200 ease-out ${
                            active ? 'translate-x-6' : 'translate-x-1'
                        }`}
                    />
                </button>
            </div>

            <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Go Active?</DialogTitle>
                        <DialogDescription>
                            Your listings will reappear on the marketplace
                            immediately. An opponent could take one within
                            seconds and start a match. Ready to play?
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button
                            variant="ghost"
                            onClick={() => setDialogOpen(false)}
                            disabled={processing}
                        >
                            Stay inactive
                        </Button>
                        <Button
                            variant="gradient"
                            onClick={handleConfirmActivate}
                            disabled={processing}
                        >
                            {processing ? 'Activating…' : 'Go active'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
