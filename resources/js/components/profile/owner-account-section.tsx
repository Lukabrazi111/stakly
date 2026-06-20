import { ShareProfileButton } from '@/components/profile/share-profile-button';
import { useT } from '@/lib/i18n';

interface Props {
    profileUrl: string;
    username: string;
}

/** Owner-only "Your account" block. Parent gates on auth.user.id === user.id. */
export function OwnerAccountSection({ profileUrl, username }: Props) {
    const t = useT();

    return (
        <section
            aria-labelledby="owner-account-heading"
            className="rounded-2xl border border-border/60 bg-secondary p-6 md:p-8"
        >
            <header className="mb-4">
                <h2
                    id="owner-account-heading"
                    className="font-display text-lg font-semibold text-foreground"
                >
                    {t('Your account')}
                </h2>
                <p className="mt-1 text-sm text-muted-foreground">
                    {t('Only you can see this section.')}
                </p>
            </header>

            <ShareProfileButton profileUrl={profileUrl} username={username} />
        </section>
    );
}
