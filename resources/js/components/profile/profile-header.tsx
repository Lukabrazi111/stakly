import { Link, usePage } from '@inertiajs/react';
import { VerificationChip } from '@/components/profile/verification-chip';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { useInitials } from '@/hooks/use-initials';
import { edit as editProfile } from '@/routes/profile';
import type { UserProfile } from '@/types';

interface Props {
    user: UserProfile;
}

export function ProfileHeader({ user }: Props) {
    const { auth } = usePage().props;
    const getInitials = useInitials();
    const isOwnProfile = auth.user?.id === user.id;

    const joinedDate = new Intl.DateTimeFormat('en-US', {
        month: 'short',
        year: 'numeric',
    }).format(new Date(user.member_since));

    return (
        <section className="rounded-2xl border border-border/60 bg-card p-6 md:p-8">
            {/* Identity row — avatar + name (left), Edit profile (right,
                owner-only). Completion rate moved out of the hero (its
                breakdown lives in the Data overview below); Active Mode
                lives on /listings/mine via ActiveModeToggle. */}
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
                        <p className="mt-1 truncate text-sm text-muted-foreground">
                            @{user.username}
                        </p>
                    </div>
                </div>

                {isOwnProfile && (
                    <Button variant="ghost" size="sm" asChild>
                        <Link href={editProfile().url}>Edit profile</Link>
                    </Button>
                )}
            </div>

            {/* Chip strip — verification + member-since. Horizontal scroll
                on mobile so chips don't wrap into tall stacks; reverts to
                wrap on sm+ where the row has room. */}
            <div className="-mx-2 mt-5 flex flex-nowrap items-center gap-2 overflow-x-auto px-2 pb-1 sm:flex-wrap sm:overflow-visible sm:pb-0 [&::-webkit-scrollbar]:hidden">
                {user.chess_com_username && (
                    <VerificationChip
                        provider="chess_com"
                        username={user.chess_com_username}
                    />
                )}
                {user.lichess_username && (
                    <VerificationChip
                        provider="lichess"
                        username={user.lichess_username}
                    />
                )}
                <span className="inline-flex shrink-0 items-center rounded-full border border-border/60 bg-card px-3 py-1 text-xs font-medium text-muted-foreground">
                    Joined {joinedDate}
                </span>
            </div>

            {user.bio && (
                <p className="mt-5 max-w-prose text-base leading-relaxed whitespace-pre-line text-foreground/90">
                    {user.bio}
                </p>
            )}
        </section>
    );
}
