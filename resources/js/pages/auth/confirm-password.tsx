import { Form } from '@inertiajs/react';
import { authInputClass } from '@/components/auth/input-styles';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { PageMeta } from '@/components/site/page-meta';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useT } from '@/lib/i18n';
import { store } from '@/routes/password/confirm';

export default function ConfirmPassword() {
    const t = useT();

    return (
        <>
            <PageMeta
                title={t('Confirm password')}
                description={t('Confirm your password.')}
                noindex
            />

            <Form
                {...store.form()}
                resetOnSuccess={['password']}
                className="flex flex-col gap-6"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="flex flex-col gap-2">
                            <Label htmlFor="password" className="text-sm">
                                {t('Password')}
                            </Label>
                            <PasswordInput
                                id="password"
                                name="password"
                                placeholder="••••••••"
                                autoComplete="current-password"
                                autoFocus
                                required
                                className={authInputClass}
                            />
                            <InputError message={errors.password} />
                        </div>

                        <Button
                            type="submit"
                            variant="gradient"
                            size="pill"
                            className="w-full"
                            disabled={processing}
                            data-test="confirm-password-button"
                        >
                            {processing && <Spinner />}
                            {t('Confirm password')}
                        </Button>
                    </>
                )}
            </Form>
        </>
    );
}

ConfirmPassword.layout = {
    title: 'Confirm your password',
    description: 'This is a secure area. Confirm your password to continue.',
};
