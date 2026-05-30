import { router } from '@inertiajs/react';
import { MailWarning } from 'lucide-react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import { send } from '@/routes/verification';

interface Props {
    className?: string;
}

const COOLDOWN_STORAGE_KEY = 'stakly:verify-email-cooldown-until';

function readCooldownRemaining(): number {
    if (typeof window === 'undefined') {
        return 0;
    }

    const raw = window.localStorage.getItem(COOLDOWN_STORAGE_KEY);
    const until = raw ? parseInt(raw, 10) : 0;
    const remaining = Math.max(0, Math.ceil((until - Date.now()) / 1000));

    if (remaining === 0 && raw) {
        window.localStorage.removeItem(COOLDOWN_STORAGE_KEY);
    }

    return remaining;
}

export function UnverifiedChip({ className }: Props) {
    const [sending, setSending] = useState(false);
    // Default to 0 during SSR + first client paint, then sync from
    // localStorage after mount. Initializing via `readCooldownRemaining()`
    // would render different labels ("Verify email" vs "Resend in Xs") on
    // server vs client and cause a hydration mismatch.
    const [cooldown, setCooldown] = useState(0);

    useEffect(() => {
        setCooldown(readCooldownRemaining());
    }, []);

    // Tick down while cooldown is active.
    useEffect(() => {
        if (cooldown <= 0) {
            return;
        }

        const id = window.setInterval(() => {
            setCooldown(readCooldownRemaining());
        }, 1000);

        return () => window.clearInterval(id);
    }, [cooldown]);

    // Re-read cooldown when useFlashToast writes a fresh value to localStorage
    // (e.g. server flashed `verify_cooldown_seconds` after register/resend).
    useEffect(() => {
        const sync = () => setCooldown(readCooldownRemaining());

        window.addEventListener('stakly:verify-cooldown-changed', sync);

        return () =>
            window.removeEventListener('stakly:verify-cooldown-changed', sync);
    }, []);

    const disabled = sending || cooldown > 0;

    const resend = () => {
        if (disabled) {
            return;
        }

        setSending(true);

        router.post(
            send().url,
            {},
            {
                preserveScroll: true,
                preserveState: true,
                onFinish: () => setSending(false),
                onError: (errors) => {
                    const message =
                        typeof errors === 'object' && errors !== null
                            ? Object.values(errors)[0]
                            : null;
                    toast.error(
                        typeof message === 'string'
                            ? message
                            : 'Could not resend right now. Try again in a moment.',
                    );
                },
            },
        );
    };

    const label = (() => {
        if (sending) {
            return 'Sending...';
        }

        if (cooldown > 0) {
            return `Resend in ${cooldown}s`;
        }

        return 'Verify email';
    })();

    return (
        <button
            type="button"
            onClick={resend}
            disabled={disabled}
            className={`inline-flex items-center gap-2 rounded-full border border-warning/30 bg-warning/10 px-3 py-1.5 text-xs font-medium text-warning transition-colors duration-200 ease-out hover:border-warning/50 hover:bg-warning/20 focus-visible:ring-2 focus-visible:ring-warning focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-60 disabled:hover:border-warning/30 disabled:hover:bg-warning/10 ${className ?? ''}`}
            title={
                cooldown > 0
                    ? `Wait ${cooldown}s before requesting another email`
                    : 'Verify your email to create listings — click to resend'
            }
        >
            <MailWarning className="size-3.5" />
            <span>{label}</span>
        </button>
    );
}
