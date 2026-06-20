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
import { useT } from '@/lib/i18n';
import { update as activeModeUpdate } from '@/routes/active-mode';

interface Props {
    /** Number of currently-Open listings the user has. Drives the contextual
     *  hint shown when Active Mode is on but there's nothing on the board. */
    listingsCount: number;
}

/**
 * Global "online/offline" toggle for the user's listings.
 *
 * Asymmetric-risk handling: Active → Inactive posts directly; Inactive →
 * Active requires a confirmation dialog because reactivating can trigger
 * an immediate match within seconds.
 */
export function ActiveModeToggle({ listingsCount }: Props) {
    const t = useT();
    const { auth } = usePage().props;
    const active = auth.user?.is_active_mode ?? true;
    const hasNoListings = listingsCount === 0;

    const [dialogOpen, setDialogOpen] = useState(false);
    const [processing, setProcessing] = useState(false);

    const handleToggleClick = () => {
        if (active) {
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
                <div className="flex flex-col items-start sm:items-end">
                    <span className="text-sm font-medium text-foreground">
                        {active ? t('Active Mode') : t('Inactive Mode')}
                    </span>
                    <span
                        className={`text-xs ${active && hasNoListings ? 'text-warning' : 'text-muted-foreground'}`}
                    >
                        {active
                            ? hasNoListings
                                ? t('Post a listing to appear on the board')
                                : t('Listings visible to the marketplace')
                            : t('Listings hidden from the marketplace')}
                    </span>
                </div>
                <button
                    type="button"
                    role="switch"
                    aria-checked={active}
                    aria-label={
                        active
                            ? t('Switch to Inactive Mode')
                            : t('Switch to Active Mode')
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
                        <DialogTitle>{t('Go Active?')}</DialogTitle>
                        <DialogDescription>
                            {t(
                                'Your listings will reappear on the marketplace immediately. An opponent could take one within seconds and start a match. Ready to play?',
                            )}
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button
                            variant="ghost"
                            onClick={() => setDialogOpen(false)}
                            disabled={processing}
                        >
                            {t('Stay inactive')}
                        </Button>
                        <Button
                            variant="gradient"
                            onClick={handleConfirmActivate}
                            disabled={processing}
                        >
                            {processing ? t('Activating…') : t('Go active')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
