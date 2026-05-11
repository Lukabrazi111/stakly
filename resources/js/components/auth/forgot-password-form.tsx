import { Form } from '@inertiajs/react';
import { authInputClass } from '@/components/auth/input-styles';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { email as emailRoute } from '@/routes/password';

interface ForgotPasswordFormProps {
    onSwitchToLogin: () => void;
}

export function ForgotPasswordForm({
    onSwitchToLogin,
}: ForgotPasswordFormProps) {
    return (
        <Form {...emailRoute.form()} className="flex flex-col gap-6">
            {({ processing, errors }) => (
                <>
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

                    <Button
                        type="submit"
                        variant="gradient"
                        size="pill"
                        className="w-full"
                        disabled={processing}
                        data-test="email-password-reset-link-button"
                    >
                        {processing && <Spinner />}
                        Send reset link
                    </Button>

                    <p className="text-muted-foreground text-center text-sm">
                        Remembered it?{' '}
                        <button
                            type="button"
                            onClick={onSwitchToLogin}
                            className="text-foreground hover:text-primary cursor-pointer font-medium transition-colors"
                        >
                            Sign in
                        </button>
                    </p>
                </>
            )}
        </Form>
    );
}
