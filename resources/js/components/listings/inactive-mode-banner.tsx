import { usePage } from '@inertiajs/react';
import { AlertCircle } from 'lucide-react';

/** Banner shown inside /listings/mine when the user's Active Mode is off. */
export function InactiveModeBanner() {
    const { auth } = usePage().props;

    if (!auth.user || auth.user.is_active_mode) {
        return null;
    }

    return (
        <div
            role="status"
            className="mb-6 flex items-start gap-3 rounded-xl border border-warning/40 bg-warning/10 p-4 text-warning"
        >
            <AlertCircle className="size-5 shrink-0" aria-hidden="true" />
            <div className="flex-1">
                <p className="text-sm font-medium text-foreground">
                    You&apos;re in Inactive Mode
                </p>
                <p className="mt-0.5 text-sm text-muted-foreground">
                    Your listings are hidden from the public marketplace and
                    your profile. Toggle Active Mode above to bring them back.
                </p>
            </div>
        </div>
    );
}
