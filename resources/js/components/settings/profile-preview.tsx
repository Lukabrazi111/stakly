import { Link } from '@inertiajs/react';
import { ArrowUpRight, Eye } from 'lucide-react';
import { VerificationChip } from '@/components/profile/verification-chip';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { useInitials } from '@/hooks/use-initials';
import { useT } from '@/lib/i18n';

interface Props {
    name: string;
    username: string;
    bio: string;
    /** Staged-blob preview URL when picking a new avatar, otherwise the
     *  persisted URL. Undefined falls back to gradient initials. */
    avatarSrc: string | undefined;
    joinedAt: string;
    linkedAccounts: ReadonlyArray<{
        provider: 'chess_com' | 'lichess';
        username: string;
    }>;
    profileUrl: string;
}

/** Live preview of the public profile shown above the settings form. */
export function ProfilePreview({
    name,
    username,
    bio,
    avatarSrc,
    joinedAt,
    linkedAccounts,
    profileUrl,
}: Props) {
    const t = useT();
    const getInitials = useInitials();

    const joinedLabel = new Intl.DateTimeFormat(undefined, {
        month: 'short',
        year: 'numeric',
    }).format(new Date(joinedAt));

    const trimmedName = name.trim() || t('Your name');
    const trimmedBio = bio.trim();

    return (
        <section
            aria-label={t('Public profile preview')}
            className="rounded-2xl border border-border/60 bg-card p-6"
        >
            <header className="mb-4 flex items-center justify-between gap-3">
                <span className="inline-flex items-center gap-1.5 text-[11px] font-medium tracking-widest text-muted-foreground uppercase">
                    <Eye className="size-3.5" aria-hidden="true" />
                    {t('Preview')}
                </span>
                <Link
                    href={profileUrl}
                    className="inline-flex items-center gap-1 text-xs font-medium text-primary transition-colors hover:text-primary/80"
                >
                    {t('View public profile')}
                    <ArrowUpRight className="size-3.5" aria-hidden="true" />
                </Link>
            </header>

            <div className="flex items-center gap-4">
                <Avatar className="size-16 shrink-0 overflow-hidden rounded-full ring-2 ring-border/60">
                    <AvatarImage src={avatarSrc} alt={trimmedName} />
                    <AvatarFallback className="bg-gradient-primary text-xl font-semibold text-primary-foreground">
                        {getInitials(trimmedName)}
                    </AvatarFallback>
                </Avatar>

                <div className="min-w-0">
                    <h3 className="truncate font-display text-xl font-bold tracking-tight text-foreground">
                        {trimmedName}
                    </h3>
                    <p className="mt-0.5 truncate text-sm text-muted-foreground">
                        @{username}
                    </p>
                </div>
            </div>

            <div className="mt-4 flex flex-wrap items-center gap-2">
                {linkedAccounts.map((account) => (
                    <VerificationChip
                        key={account.provider}
                        provider={account.provider}
                        username={account.username}
                    />
                ))}
                <span className="inline-flex shrink-0 items-center rounded-full border border-border/60 bg-card px-3 py-1 text-xs font-medium text-muted-foreground">
                    {t('Joined :date', { date: joinedLabel })}
                </span>
            </div>

            {trimmedBio ? (
                <p className="mt-4 max-w-prose text-sm leading-relaxed whitespace-pre-line text-foreground/90">
                    {trimmedBio}
                </p>
            ) : (
                <p className="mt-4 text-sm text-muted-foreground italic">
                    {t(
                        'Bio is empty — add one to tell other players a bit about yourself.',
                    )}
                </p>
            )}
        </section>
    );
}
