import { router } from '@inertiajs/react';
import { useEffect } from 'react';
import { toast } from 'sonner';
import type { FlashToast } from '@/types/ui';

const COOLDOWN_STORAGE_KEY = 'stakly:verify-email-cooldown-until';

export function useFlashToast(): void {
    useEffect(() => {
        return router.on('flash', (event) => {
            const flash = event.detail.flash;

            const toastData = flash?.toast as FlashToast | undefined;

            if (toastData) {
                toast[toastData.type](toastData.message);
            }

            const cooldownSeconds = flash?.verify_cooldown_seconds;

            if (typeof cooldownSeconds === 'number' && cooldownSeconds > 0) {
                const until = Date.now() + cooldownSeconds * 1000;
                window.localStorage.setItem(
                    COOLDOWN_STORAGE_KEY,
                    String(until),
                );
                window.dispatchEvent(
                    new CustomEvent('stakly:verify-cooldown-changed'),
                );
            }
        });
    }, []);
}
