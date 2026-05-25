import { Form } from '@inertiajs/react';
import { authInputClass } from '@/components/auth/input-styles';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { store } from '@/routes/register';

interface RegisterFormProps {
    onSwitchToLogin: () => void;
}

export function RegisterForm({ onSwitchToLogin }: RegisterFormProps) {
    return (
        <Form
            {...store.form()}
            resetOnSuccess={['password', 'password_confirmation']}
            disableWhileProcessing
            className="flex flex-col gap-6"
        >
            {({ processing, errors }) => (
                <>
                    <div className="flex flex-col gap-4">
                        <div className="flex flex-col gap-2">
                            <Label htmlFor="name" className="text-sm">
                                Name
                            </Label>
                            <Input
                                id="name"
                                name="name"
                                type="text"
                                autoFocus
                                autoComplete="name"
                                placeholder="Your name"
                                required
                                className={authInputClass}
                            />
                            <InputError message={errors.name} />
                        </div>

                        <div className="flex flex-col gap-2">
                            <Label htmlFor="email" className="text-sm">
                                Email
                            </Label>
                            <Input
                                id="email"
                                name="email"
                                type="email"
                                autoComplete="email"
                                placeholder="you@example.com"
                                required
                                className={authInputClass}
                            />
                            <InputError message={errors.email} />
                        </div>

                        <div className="flex flex-col gap-2">
                            <Label htmlFor="password" className="text-sm">
                                Password
                            </Label>
                            <PasswordInput
                                id="password"
                                name="password"
                                autoComplete="new-password"
                                placeholder="At least 8 characters"
                                required
                                className={authInputClass}
                            />
                            <InputError message={errors.password} />
                        </div>

                        <div className="flex flex-col gap-2">
                            <Label
                                htmlFor="password_confirmation"
                                className="text-sm"
                            >
                                Confirm password
                            </Label>
                            <PasswordInput
                                id="password_confirmation"
                                name="password_confirmation"
                                autoComplete="new-password"
                                placeholder="Repeat your password"
                                required
                                className={authInputClass}
                            />
                            <InputError
                                message={errors.password_confirmation}
                            />
                        </div>
                    </div>

                    <Button
                        type="submit"
                        variant="gradient"
                        size="pill"
                        className="w-full"
                        disabled={processing}
                        data-test="register-user-button"
                    >
                        {processing && <Spinner />}
                        Create account
                    </Button>

                    <p className="text-center text-sm text-muted-foreground">
                        Already have an account?{' '}
                        <button
                            type="button"
                            onClick={onSwitchToLogin}
                            className="cursor-pointer font-medium text-foreground transition-colors hover:text-primary"
                        >
                            Sign in
                        </button>
                    </p>
                </>
            )}
        </Form>
    );
}
