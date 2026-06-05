import { Form } from '@inertiajs/react';
import { authInputClass } from '@/components/auth/input-styles';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NeonCheckbox } from '@/components/ui/neon-checkbox';
import { Spinner } from '@/components/ui/spinner';
import { useT } from '@/lib/i18n';
import { store } from '@/routes/login';

interface LoginFormProps {
    status?: string;
    onSwitchToRegister: () => void;
    onSwitchToForgotPassword: () => void;
}

export function LoginForm({
    status,
    onSwitchToRegister,
    onSwitchToForgotPassword,
}: LoginFormProps) {
    const t = useT();

    return (
        <Form
            {...store.form()}
            resetOnSuccess={['password']}
            className="flex flex-col gap-6"
        >
            {({ processing, errors }) => (
                <>
                    {status && (
                        <p
                            role="status"
                            className="text-center text-sm text-success"
                        >
                            {status}
                        </p>
                    )}

                    <div className="flex flex-col gap-4">
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

                        <div className="flex flex-col gap-2">
                            <div className="flex items-center justify-between">
                                <Label htmlFor="password" className="text-sm">
                                    {t('Password')}
                                </Label>
                                <button
                                    type="button"
                                    onClick={onSwitchToForgotPassword}
                                    className="cursor-pointer text-xs text-muted-foreground transition-colors hover:text-primary"
                                >
                                    {t('Forgot password?')}
                                </button>
                            </div>
                            <PasswordInput
                                id="password"
                                name="password"
                                autoComplete="current-password"
                                placeholder="••••••••"
                                required
                                className={authInputClass}
                            />
                            <InputError message={errors.password} />
                        </div>

                        <label
                            htmlFor="remember"
                            className="flex cursor-pointer items-center gap-2 text-sm text-muted-foreground"
                        >
                            <NeonCheckbox id="remember" name="remember" />
                            <span>{t('Remember me on this device')}</span>
                        </label>
                    </div>

                    <Button
                        type="submit"
                        variant="gradient"
                        size="pill"
                        className="w-full"
                        disabled={processing}
                        data-test="login-button"
                    >
                        {processing && <Spinner />}
                        {t('Sign in')}
                    </Button>

                    <p className="text-center text-sm text-muted-foreground">
                        {t("Don't have an account?")}{' '}
                        <button
                            type="button"
                            onClick={onSwitchToRegister}
                            className="cursor-pointer font-medium text-foreground transition-colors hover:text-primary"
                        >
                            {t('Sign up')}
                        </button>
                    </p>
                </>
            )}
        </Form>
    );
}
