import { Form, setLayoutProps } from '@inertiajs/react';
import { REGEXP_ONLY_DIGITS } from 'input-otp';
import { useMemo, useState } from 'react';
import { authInputClass } from '@/components/auth/input-styles';
import InputError from '@/components/input-error';
import { PageMeta } from '@/components/site/page-meta';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    InputOTP,
    InputOTPGroup,
    InputOTPSlot,
} from '@/components/ui/input-otp';
import { Spinner } from '@/components/ui/spinner';
import { OTP_MAX_LENGTH } from '@/hooks/use-two-factor-auth';
import { store } from '@/routes/two-factor/login';

export default function TwoFactorChallenge() {
    const [showRecoveryInput, setShowRecoveryInput] = useState<boolean>(false);
    const [code, setCode] = useState<string>('');

    const viewMeta = useMemo<{
        title: string;
        description: string;
        toggleText: string;
    }>(() => {
        if (showRecoveryInput) {
            return {
                title: 'Use a recovery code',
                description:
                    'Enter one of the emergency recovery codes you saved when you set up two-factor authentication.',
                toggleText: 'Use an authentication code instead',
            };
        }

        return {
            title: 'Two-factor authentication',
            description:
                'Enter the 6-digit code from your authenticator app to continue.',
            toggleText: 'Use a recovery code instead',
        };
    }, [showRecoveryInput]);

    setLayoutProps({
        title: viewMeta.title,
        description: viewMeta.description,
    });

    const toggleRecoveryMode = (clearErrors: () => void): void => {
        setShowRecoveryInput(!showRecoveryInput);
        clearErrors();
        setCode('');
    };

    return (
        <>
            <PageMeta
                title="Two-factor authentication"
                description="Enter your 2FA code to continue."
                noindex
            />

            <Form
                {...store.form()}
                className="flex flex-col gap-6"
                resetOnError
                resetOnSuccess={!showRecoveryInput}
            >
                {({ errors, processing, clearErrors }) => (
                    <>
                        {showRecoveryInput ? (
                            <div className="flex flex-col gap-2">
                                <Input
                                    name="recovery_code"
                                    type="text"
                                    placeholder="Recovery code"
                                    autoFocus
                                    required
                                    className={authInputClass}
                                />
                                <InputError message={errors.recovery_code} />
                            </div>
                        ) : (
                            <div className="flex flex-col items-center gap-3">
                                <InputOTP
                                    name="code"
                                    maxLength={OTP_MAX_LENGTH}
                                    value={code}
                                    onChange={(value) => setCode(value)}
                                    disabled={processing}
                                    pattern={REGEXP_ONLY_DIGITS}
                                    autoFocus
                                >
                                    <InputOTPGroup className="gap-2">
                                        {Array.from(
                                            { length: OTP_MAX_LENGTH },
                                            (_, index) => (
                                                <InputOTPSlot
                                                    key={index}
                                                    index={index}
                                                    className="h-12 w-11 rounded-lg border border-border bg-card text-lg font-semibold first:rounded-l-lg last:rounded-r-lg"
                                                />
                                            ),
                                        )}
                                    </InputOTPGroup>
                                </InputOTP>
                                <InputError message={errors.code} />
                            </div>
                        )}

                        <Button
                            type="submit"
                            variant="gradient"
                            size="pill"
                            className="w-full"
                            disabled={processing}
                        >
                            {processing && <Spinner />}
                            Continue
                        </Button>

                        <p className="text-center text-sm text-muted-foreground">
                            <button
                                type="button"
                                onClick={() => toggleRecoveryMode(clearErrors)}
                                className="cursor-pointer font-medium text-foreground transition-colors hover:text-primary"
                            >
                                {viewMeta.toggleText}
                            </button>
                        </p>
                    </>
                )}
            </Form>
        </>
    );
}
