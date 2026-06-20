import { Form } from '@inertiajs/react';
import { authInputClass } from '@/components/auth/input-styles';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useT } from '@/lib/i18n';
import { email as emailRoute } from '@/routes/password';

interface ForgotPasswordFormProps {
    onSwitchToLogin: () => void;
}

export function ForgotPasswordForm({
    onSwitchToLogin,
}: ForgotPasswordFormProps) {
    const t = useT();

    return (
        <Form {...emailRoute.form()} className="flex flex-col gap-6">
            {({ processing, errors }) => (
                <>
                    <div className="flex flex-col gap-2">
                        <Label htmlFor="email" className="text-sm">
                            {t('Email')}
                        </Label>
                        <Input
                            id="email"
                            name="email"
                            type="email"
                            autoFocus
                            autoComplete="email"
                            placeholder="you@example.com"
                            required
                            className={authInputClass}
                        />
                        <InputError message={errors.email} />
                    </div>

                    <Button
                        type="submit"
                        variant="gradient"
                        size="pill"
                        className="w-full"
                        disabled={processing}
                        data-test="email-password-reset-link-button"
                    >
                        {processing && <Spinner />}
                        {t('Send reset link')}
                    </Button>

                    <p className="text-center text-sm text-muted-foreground">
                        {t('Remembered it?')}{' '}
                        <button
                            type="button"
                            onClick={onSwitchToLogin}
                            className="cursor-pointer font-medium text-foreground transition-colors hover:text-primary"
                        >
                            {t('Sign in')}
                        </button>
                    </p>
                </>
            )}
        </Form>
    );
}
