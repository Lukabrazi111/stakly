import { Form } from '@inertiajs/react';
import { authInputClass } from '@/components/auth/input-styles';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
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
                            className="text-success text-center text-sm"
                        >
                            {status}
                        </p>
                    )}

                    <div className="flex flex-col gap-4">
                        <div className="flex flex-col gap-2">
                            <Label htmlFor="email" className="text-sm">
                                Email
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
                                    Password
                                </Label>
                                <button
                                    type="button"
                                    onClick={onSwitchToForgotPassword}
                                    className="text-muted-foreground hover:text-primary cursor-pointer text-xs transition-colors"
                                >
                                    Forgot password?
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
                            className="text-muted-foreground flex cursor-pointer items-center gap-2.5 text-sm"
                        >
                            <Checkbox
                                id="remember"
                                name="remember"
                                className="border-border"
                            />
                            <span>Remember me on this device</span>
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
                        Sign in
                    </Button>

                    <p className="text-muted-foreground text-center text-sm">
                        Don't have an account?{' '}
                        <button
                            type="button"
                            onClick={onSwitchToRegister}
                            className="text-foreground hover:text-primary cursor-pointer font-medium transition-colors"
                        >
                            Sign up
                        </button>
                    </p>
                </>
            )}
        </Form>
    );
}
