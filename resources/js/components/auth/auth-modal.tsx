import { usePage } from '@inertiajs/react';
import * as DialogPrimitive from '@radix-ui/react-dialog';
import { X } from 'lucide-react';
import { AnimatePresence, motion } from 'motion/react';
import { useEffect } from 'react';
import type { AuthView } from '@/components/auth/auth-modal-provider';
import { useAuthModal } from '@/components/auth/auth-modal-provider';
import { ForgotPasswordForm } from '@/components/auth/forgot-password-form';
import { LoginForm } from '@/components/auth/login-form';
import { RegisterForm } from '@/components/auth/register-form';
import {
    Dialog,
    DialogDescription,
    DialogOverlay,
    DialogPortal,
    DialogTitle,
} from '@/components/ui/dialog';

const META: Record<AuthView, { title: string; description: string }> = {
    login: {
        title: 'Welcome back',
        description: 'Sign in to keep playing.',
    },
    register: {
        title: 'Create your account',
        description: 'Start staking your skill in minutes.',
    },
    'forgot-password': {
        title: 'Reset your password',
        description: "We'll email you a link to set a new one.",
    },
};

export function AuthModal() {
    const { open, close, view, setView } = useAuthModal();
    const page = usePage();
    const status =
        typeof page.props.status === 'string' ? page.props.status : undefined;
    const user = page.props.auth?.user ?? null;

    useEffect(() => {
        if (user && open) {
            close();
        }
    }, [user, open, close]);

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                if (!next) {
                    close();
                }
            }}
        >
            <DialogPortal>
                <DialogOverlay />

                <div
                    aria-hidden
                    className="data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 pointer-events-none fixed inset-0 z-50 flex items-center justify-center duration-300"
                >
                    <div
                        className="size-[460px] rounded-full blur-[100px]"
                        style={{
                            background:
                                'radial-gradient(circle, color-mix(in srgb, var(--gradient-glow) 22%, transparent) 0%, transparent 65%)',
                        }}
                    />
                </div>

                <DialogPrimitive.Content
                    onOpenAutoFocus={(e) => {
                        e.preventDefault();
                    }}
                    className="border-glow bg-card data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95 fixed top-[50%] left-[50%] z-50 w-full max-w-[calc(100%-2rem)] translate-x-[-50%] translate-y-[-50%] overflow-hidden rounded-2xl border p-0 duration-200 sm:max-w-md"
                >
                    <DialogTitle className="sr-only">
                        Authenticate to Stakly
                    </DialogTitle>
                    <DialogDescription className="sr-only">
                        Sign in, create an account, or reset your password.
                    </DialogDescription>

                    <DialogPrimitive.Close
                        aria-label="Close"
                        className="text-muted-foreground hover:text-foreground hover:bg-primary/10 focus-visible:ring-primary/25 focus-visible:ring-offset-background absolute top-3.5 right-3.5 z-10 inline-flex size-9 cursor-pointer items-center justify-center rounded-full transition-colors duration-150 ease-out focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none disabled:pointer-events-none"
                    >
                        <X className="size-4" />
                        <span className="sr-only">Close</span>
                    </DialogPrimitive.Close>

                    <motion.div
                        layout
                        transition={{
                            layout: {
                                type: 'spring',
                                stiffness: 420,
                                damping: 38,
                                mass: 0.6,
                            },
                        }}
                    >
                        <AnimatePresence mode="wait" initial={false}>
                            <motion.div
                                key={view}
                                initial={{ opacity: 0, y: 6 }}
                                animate={{ opacity: 1, y: 0 }}
                                exit={{ opacity: 0, y: -6 }}
                                transition={{
                                    duration: 0.18,
                                    ease: [0.4, 0, 0.2, 1],
                                }}
                                className="flex flex-col gap-6 p-8 pt-10"
                            >
                                <div className="flex flex-col gap-2 text-center">
                                    <h2 className="font-display text-foreground text-2xl font-bold tracking-tight">
                                        {META[view].title}
                                    </h2>
                                    <p className="text-muted-foreground text-sm">
                                        {META[view].description}
                                    </p>
                                </div>

                                {view === 'login' && (
                                    <LoginForm
                                        status={status}
                                        onSwitchToRegister={() =>
                                            setView('register')
                                        }
                                        onSwitchToForgotPassword={() =>
                                            setView('forgot-password')
                                        }
                                    />
                                )}
                                {view === 'register' && (
                                    <RegisterForm
                                        onSwitchToLogin={() => setView('login')}
                                    />
                                )}
                                {view === 'forgot-password' && (
                                    <ForgotPasswordForm
                                        onSwitchToLogin={() => setView('login')}
                                    />
                                )}
                            </motion.div>
                        </AnimatePresence>
                    </motion.div>
                </DialogPrimitive.Content>
            </DialogPortal>
        </Dialog>
    );
}
