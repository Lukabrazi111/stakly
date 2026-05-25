import { Link, usePage } from '@inertiajs/react';
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
        <section className="rounded-2xl border border-border/60 bg-card/60 p-6 md:p-8">
            <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div className="flex items-center gap-4">
                    <Avatar className="size-20 overflow-hidden rounded-full ring-2 ring-border/60 transition-shadow duration-200 ease-out hover:shadow-glow-sm hover:ring-primary/50">
                        <AvatarImage
                            src={user.avatar_url ?? undefined}
                            alt={user.name}
                        />
                        <AvatarFallback className="bg-gradient-primary text-2xl font-semibold text-primary-foreground">
                            {getInitials(user.name)}
                        </AvatarFallback>
                    </Avatar>

                    <div className="min-w-0">
                        <h1 className="truncate font-display text-3xl font-bold tracking-tight text-foreground md:text-4xl">
                            {user.name}
                        </h1>
                        <div className="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-muted-foreground">
                            <span>@{user.username}</span>
                            <span className="text-border">·</span>
                            <span>Joined {joinedDate}</span>
                        </div>
                    </div>
                </div>

                {isOwnProfile && (
                    <Button variant="ghost" asChild>
                        <Link href={editProfile().url}>Edit profile</Link>
                    </Button>
                )}
            </div>

            {user.bio && (
                <p className="mt-6 text-sm leading-relaxed whitespace-pre-line text-foreground/80">
                    {user.bio}
                </p>
            )}
        </section>
    );
}
