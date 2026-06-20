import { Form } from '@inertiajs/react';
import { authInputClass } from '@/components/auth/input-styles';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { PageMeta } from '@/components/site/page-meta';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useT } from '@/lib/i18n';
import { update } from '@/routes/password';

type Props = {
    token: string;
    email: string;
};

export default function ResetPassword({ token, email }: Props) {
    const t = useT();

    return (
        <>
            <PageMeta
                title={t('Reset password')}
                description={t('Reset your Stakly password.')}
                noindex
            />

            <Form
                {...update.form()}
                transform={(data) => ({ ...data, token, email })}
                resetOnSuccess={['password', 'password_confirmation']}
                className="flex flex-col gap-6"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="flex flex-col gap-4">
                            <div className="flex flex-col gap-2">
                                <Label htmlFor="email" className="text-sm">
                                    {t('Email')}
                                </Label>
                                <Input
                                    id="email"
                                    name="email"
                                    type="email"
                                    autoComplete="email"
                                    value={email}
                                    readOnly
                                    className={`${authInputClass} opacity-70`}
                                />
                                <InputError message={errors.email} />
                            </div>

                            <div className="flex flex-col gap-2">
                                <Label htmlFor="password" className="text-sm">
                                    {t('New password')}
                                </Label>
                                <PasswordInput
                                    id="password"
                                    name="password"
                                    autoComplete="new-password"
                                    placeholder={t('At least 8 characters')}
                                    required
                                    autoFocus
                                    className={authInputClass}
                                />
                                <InputError message={errors.password} />
                            </div>

                            <div className="flex flex-col gap-2">
                                <Label
                                    htmlFor="password_confirmation"
                                    className="text-sm"
                                >
                                    {t('Confirm new password')}
                                </Label>
                                <PasswordInput
                                    id="password_confirmation"
                                    name="password_confirmation"
                                    autoComplete="new-password"
                                    placeholder={t('Repeat your password')}
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
                            data-test="reset-password-button"
                        >
                            {processing && <Spinner />}
                            {t('Reset password')}
                        </Button>
                    </>
                )}
            </Form>
        </>
    );
}

ResetPassword.layout = {
    title: 'Set a new password',
    description: 'Pick a strong one — at least 8 characters.',
};
