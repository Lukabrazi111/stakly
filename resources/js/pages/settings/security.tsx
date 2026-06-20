import { Form } from '@inertiajs/react';
import { CheckCircle2, ShieldCheck, ShieldOff } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import SecurityController from '@/actions/App/Http/Controllers/Settings/SecurityController';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { PageMeta } from '@/components/site/page-meta';
import TwoFactorRecoveryCodes from '@/components/two-factor-recovery-codes';
import TwoFactorSetupModal from '@/components/two-factor-setup-modal';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { useTwoFactorAuth } from '@/hooks/use-two-factor-auth';
import { useT } from '@/lib/i18n';
import { disable, enable } from '@/routes/two-factor';

type Props = {
    canManageTwoFactor?: boolean;
    requiresConfirmation?: boolean;
    twoFactorEnabled?: boolean;
};

export default function Security({
    canManageTwoFactor = false,
    requiresConfirmation = false,
    twoFactorEnabled = false,
}: Props) {
    const t = useT();
    const passwordInput = useRef<HTMLInputElement>(null);
    const currentPasswordInput = useRef<HTMLInputElement>(null);

    const {
        qrCodeSvg,
        hasSetupData,
        manualSetupKey,
        clearSetupData,
        clearTwoFactorAuthData,
        fetchSetupData,
        recoveryCodesList,
        fetchRecoveryCodes,
        errors,
    } = useTwoFactorAuth();
    const [showSetupModal, setShowSetupModal] = useState<boolean>(false);
    const [confirmDisableOpen, setConfirmDisableOpen] = useState(false);
    const prevTwoFactorEnabled = useRef(twoFactorEnabled);

    useEffect(() => {
        if (prevTwoFactorEnabled.current && !twoFactorEnabled) {
            clearTwoFactorAuthData();
        }

        prevTwoFactorEnabled.current = twoFactorEnabled;
    }, [twoFactorEnabled, clearTwoFactorAuthData]);

    return (
        <>
            <PageMeta
                title={t('Security settings')}
                description={t(
                    'Manage password and two-factor authentication.',
                )}
                noindex
            />

            <h1 className="sr-only">{t('Security settings')}</h1>

            <div className="space-y-6">
                {/* Section: Update password — same bg-card visual rhythm as
                    the profile page (Public profile / Account cards). */}
                <section className="space-y-6 rounded-2xl border border-border/60 bg-card p-6">
                    <header>
                        <h2 className="font-display text-base font-semibold text-foreground">
                            {t('Update password')}
                        </h2>
                        <p className="mt-0.5 text-xs text-muted-foreground">
                            {t(
                                'Use a long, unique password — at least 12 characters with a mix of letters, numbers, and symbols.',
                            )}
                        </p>
                    </header>

                    <Form
                        {...SecurityController.update.form()}
                        options={{
                            preserveScroll: true,
                        }}
                        resetOnError={[
                            'password',
                            'password_confirmation',
                            'current_password',
                        ]}
                        resetOnSuccess
                        onError={(errors) => {
                            if (errors.password) {
                                passwordInput.current?.focus();
                            }

                            if (errors.current_password) {
                                currentPasswordInput.current?.focus();
                            }
                        }}
                        className="space-y-6"
                    >
                        {({ errors, processing }) => (
                            <>
                                <div className="grid gap-2">
                                    <Label htmlFor="current_password">
                                        {t('Current password')}
                                    </Label>

                                    <PasswordInput
                                        id="current_password"
                                        ref={currentPasswordInput}
                                        name="current_password"
                                        className="block w-full"
                                        autoComplete="current-password"
                                        placeholder={t('Current password')}
                                    />

                                    <InputError
                                        message={errors.current_password}
                                    />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="password">
                                        {t('New password')}
                                    </Label>

                                    <PasswordInput
                                        id="password"
                                        ref={passwordInput}
                                        name="password"
                                        className="block w-full"
                                        autoComplete="new-password"
                                        placeholder={t('New password')}
                                    />

                                    <InputError message={errors.password} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="password_confirmation">
                                        {t('Confirm password')}
                                    </Label>

                                    <PasswordInput
                                        id="password_confirmation"
                                        name="password_confirmation"
                                        className="block w-full"
                                        autoComplete="new-password"
                                        placeholder={t('Confirm password')}
                                    />

                                    <InputError
                                        message={errors.password_confirmation}
                                    />
                                </div>

                                <div className="flex items-center gap-4">
                                    <Button
                                        variant="gradient"
                                        size="pill"
                                        disabled={processing}
                                        data-test="update-password-button"
                                    >
                                        {processing
                                            ? t('Saving…')
                                            : t('Save password')}
                                    </Button>
                                </div>
                            </>
                        )}
                    </Form>
                </section>

                {canManageTwoFactor && (
                    <section className="space-y-6 rounded-2xl border border-border/60 bg-card p-6">
                        <header className="flex items-start justify-between gap-3">
                            <div>
                                <h2 className="font-display text-base font-semibold text-foreground">
                                    {t('Two-factor authentication')}
                                </h2>
                                <p className="mt-0.5 text-xs text-muted-foreground">
                                    {t(
                                        'Adds a one-time code from your phone on every sign-in. Strongly recommended on a money account.',
                                    )}
                                </p>
                            </div>
                            {twoFactorEnabled ? (
                                <span className="inline-flex shrink-0 items-center gap-1 rounded-full border border-success/30 bg-success/10 px-2 py-0.5 text-[11px] font-medium text-success">
                                    <CheckCircle2
                                        className="size-3"
                                        aria-hidden="true"
                                    />
                                    {t('Enabled')}
                                </span>
                            ) : (
                                <span className="inline-flex shrink-0 items-center gap-1 rounded-full border border-border/60 bg-muted px-2 py-0.5 text-[11px] font-medium text-muted-foreground">
                                    <ShieldOff
                                        className="size-3"
                                        aria-hidden="true"
                                    />
                                    {t('Disabled')}
                                </span>
                            )}
                        </header>

                        {twoFactorEnabled ? (
                            <div className="flex flex-col items-start justify-start space-y-4">
                                <Dialog
                                    open={confirmDisableOpen}
                                    onOpenChange={setConfirmDisableOpen}
                                >
                                    <DialogTrigger asChild>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            className="rounded-full border-destructive/30 text-destructive hover:border-destructive/50 hover:bg-destructive/10 hover:text-destructive hover:[text-shadow:none]"
                                        >
                                            {t('Disable 2FA')}
                                        </Button>
                                    </DialogTrigger>
                                    <DialogContent>
                                        <DialogHeader>
                                            <DialogTitle>
                                                {t(
                                                    'Disable two-factor authentication?',
                                                )}
                                            </DialogTitle>
                                            <DialogDescription>
                                                {t(
                                                    'After disabling, signing in will only require your password — anyone with your password gets in. On a custodial money account this is a real downgrade in safety. Keep it on unless you have a good reason.',
                                                )}
                                            </DialogDescription>
                                        </DialogHeader>
                                        <Form
                                            {...disable.form()}
                                            onSuccess={() =>
                                                setConfirmDisableOpen(false)
                                            }
                                        >
                                            {({ processing }) => (
                                                <DialogFooter>
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        onClick={() =>
                                                            setConfirmDisableOpen(
                                                                false,
                                                            )
                                                        }
                                                    >
                                                        {t('Keep 2FA on')}
                                                    </Button>
                                                    <Button
                                                        type="submit"
                                                        variant="destructive"
                                                        disabled={processing}
                                                    >
                                                        {processing
                                                            ? t('Disabling…')
                                                            : t('Disable 2FA')}
                                                    </Button>
                                                </DialogFooter>
                                            )}
                                        </Form>
                                    </DialogContent>
                                </Dialog>

                                <TwoFactorRecoveryCodes
                                    recoveryCodesList={recoveryCodesList}
                                    fetchRecoveryCodes={fetchRecoveryCodes}
                                    errors={errors}
                                />
                            </div>
                        ) : (
                            <div className="flex flex-col items-start justify-start space-y-4">
                                {hasSetupData ? (
                                    <Button
                                        variant="gradient"
                                        size="pill"
                                        onClick={() => setShowSetupModal(true)}
                                    >
                                        <ShieldCheck />
                                        {t('Continue setup')}
                                    </Button>
                                ) : (
                                    <Form
                                        {...enable.form()}
                                        onSuccess={() =>
                                            setShowSetupModal(true)
                                        }
                                    >
                                        {({ processing }) => (
                                            <Button
                                                type="submit"
                                                variant="gradient"
                                                size="pill"
                                                disabled={processing}
                                            >
                                                <ShieldCheck />
                                                {t('Enable 2FA')}
                                            </Button>
                                        )}
                                    </Form>
                                )}
                            </div>
                        )}

                        <TwoFactorSetupModal
                            isOpen={showSetupModal}
                            onClose={() => setShowSetupModal(false)}
                            requiresConfirmation={requiresConfirmation}
                            twoFactorEnabled={twoFactorEnabled}
                            qrCodeSvg={qrCodeSvg}
                            manualSetupKey={manualSetupKey}
                            clearSetupData={clearSetupData}
                            fetchSetupData={fetchSetupData}
                            errors={errors}
                        />
                    </section>
                )}
            </div>
        </>
    );
}
