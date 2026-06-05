import { router } from '@inertiajs/react';
import { MailWarning } from 'lucide-react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import { useT } from '@/lib/i18n';
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
    const t = useT();
    const [sending, setSending] = useState(false);
    // Default 0 during SSR + first paint, sync after mount — reading
    // localStorage synchronously would cause a hydration mismatch on the label.
    const [cooldown, setCooldown] = useState(0);

    useEffect(() => {
        setCooldown(readCooldownRemaining());
    }, []);

    useEffect(() => {
        if (cooldown <= 0) {
            return;
        }

        const id = window.setInterval(() => {
            setCooldown(readCooldownRemaining());
        }, 1000);

        return () => window.clearInterval(id);
    }, [cooldown]);

    // Re-read when useFlashToast writes a fresh value (server flashed
    // `verify_cooldown_seconds` after register/resend).
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
                            : t(
                                  'Could not resend right now. Try again in a moment.',
                              ),
                    );
                },
            },
        );
    };

    const label = (() => {
        if (sending) {
            return t('Sending…');
        }

        if (cooldown > 0) {
            return t('Resend in :seconds s', { seconds: cooldown });
        }

        return t('Verify email');
    })();

    return (
        <button
            type="button"
            onClick={resend}
            disabled={disabled}
            className={`inline-flex items-center gap-2 rounded-full border border-warning/30 bg-warning/10 px-3 py-1.5 text-xs font-medium text-warning transition-colors duration-200 ease-out hover:border-warning/50 hover:bg-warning/20 focus-visible:ring-2 focus-visible:ring-warning focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-60 disabled:hover:border-warning/30 disabled:hover:bg-warning/10 ${className ?? ''}`}
            title={
                cooldown > 0
                    ? t(
                          'Wait :seconds s before requesting another email',
                          { seconds: cooldown },
                      )
                    : t(
                          'Verify your email to create listings — click to resend',
                      )
            }
        >
            <MailWarning className="size-3.5" />
            <span>{label}</span>
        </button>
    );
}
