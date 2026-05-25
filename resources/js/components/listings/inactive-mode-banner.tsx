import { usePage } from '@inertiajs/react';
import { AlertCircle } from 'lucide-react';

/**
 * Reminder banner shown inside the /listings/mine page when the user's
 * global Active Mode is off. Reinforces the toggle's state at a glance so
 * a user who comes to manage listings notices they're hidden.
 *
 * Returns null when the user is active — the banner only exists to fix the
 * inactive state, not to celebrate the active one.
 */
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
