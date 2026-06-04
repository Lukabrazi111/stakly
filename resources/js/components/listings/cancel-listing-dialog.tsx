import { router } from '@inertiajs/react';
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
import { cancel as cancelRoute } from '@/routes/listings';

interface CancelListing {
    id: number;
    stake_amount: number;
}

interface Props {
    listing: CancelListing;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** Optional success callback fired after the cancel POST resolves. */
    onSuccess?: () => void;
}

/** Shared confirmation dialog for cancelling a listing. Owns the DELETE
 *  call; consumers control `open` only. */
export function CancelListingDialog({
    listing,
    open,
    onOpenChange,
    onSuccess,
}: Props) {
    const [processing, setProcessing] = useState(false);

    const handleConfirm = () => {
        setProcessing(true);
        router.delete(cancelRoute({ listing: listing.id }).url, {
            preserveScroll: true,
            onSuccess: () => {
                onSuccess?.();
                onOpenChange(false);
            },
            onFinish: () => setProcessing(false),
        });
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Cancel this listing?</DialogTitle>
                    <DialogDescription>
                        Your{' '}
                        <span className="font-semibold text-foreground">
                            ${listing.stake_amount} USDT
                        </span>{' '}
                        stake will be refunded immediately. This can&apos;t be
                        undone.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button
                        variant="ghost"
                        onClick={() => onOpenChange(false)}
                        disabled={processing}
                    >
                        Keep listing
                    </Button>
                    <Button
                        variant="destructive"
                        onClick={handleConfirm}
                        disabled={processing}
                    >
                        {processing ? 'Cancelling…' : 'Cancel & refund'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
