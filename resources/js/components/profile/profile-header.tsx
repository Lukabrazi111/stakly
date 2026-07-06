import { Link, usePage } from '@inertiajs/react';
import { VerificationChip } from '@/components/profile/verification-chip';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { useInitials } from '@/hooks/use-initials';
import { useT } from '@/lib/i18n';
import { edit as editProfile } from '@/routes/profile';
import type { UserProfile } from '@/types';

interface Props {
    user: UserProfile;
}

export function ProfileHeader({ user }: Props) {
    const t = useT();
    const { auth } = usePage().props;
    const getInitials = useInitials();
    const isOwnProfile = auth.user?.id === user.id;

    const joinedDate = new Intl.DateTimeFormat('en-US', {
        month: 'short',
        year: 'numeric',
    }).format(new Date(user.member_since));

    return (
        <section className="rounded-2xl border border-border/60 bg-card p-6 md:p-8">
            <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between sm:gap-6">
                <div className="flex items-center gap-4 md:gap-5">
                    <Avatar className="size-20 shrink-0 overflow-hidden rounded-full ring-2 ring-border/60 transition-shadow duration-200 ease-out hover:shadow-glow-sm hover:ring-primary/50 md:size-24">
                        <AvatarImage
                            src={user.avatar_url ?? undefined}
                            alt={user.name}
                        />
                        <AvatarFallback className="bg-gradient-primary text-2xl font-semibold text-primary-foreground md:text-3xl">
                            {getInitials(user.name)}
                        </AvatarFallback>
                    </Avatar>

                    <div className="min-w-0">
                        <h1 className="truncate font-display text-3xl font-bold tracking-tight text-foreground md:text-4xl">
                            {user.name}
                        </h1>
                        <p className="mt-1 flex flex-wrap items-center gap-x-1.5 text-sm text-muted-foreground">
                            <span className="min-w-0 truncate">
                                @{user.username}
                            </span>
                            <span aria-hidden="true" className="opacity-60">
                                ·
                            </span>
                            <span className="shrink-0 whitespace-nowrap">
                                {t('Joined :date', { date: joinedDate })}
                            </span>
                        </p>
                    </div>
                </div>

                {isOwnProfile && (
                    <Button variant="ghost" size="sm" asChild>
                        <Link href={editProfile().url}>
                            {t('Edit profile')}
                        </Link>
                    </Button>
                )}
            </div>

            {user.linked_accounts.length > 0 && (
                <div className="mt-5 flex flex-wrap items-center gap-2">
                    {user.linked_accounts.map((account) => (
                        <VerificationChip
                            key={account.provider}
                            provider={account.provider}
                            username={account.username}
                        />
                    ))}
                </div>
            )}

            {user.bio && (
                <p className="mt-5 max-w-prose text-base leading-relaxed whitespace-pre-line text-foreground/90">
                    {user.bio}
                </p>
            )}
        </section>
    );
}
