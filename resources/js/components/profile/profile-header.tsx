import { Link, usePage } from '@inertiajs/react';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
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
        <section className="border-border/60 bg-card/60 rounded-2xl border p-6 md:p-8">
            <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div className="flex items-center gap-4">
                    <Avatar className="size-20 overflow-hidden rounded-full">
                        <AvatarFallback className="bg-gradient-primary text-primary-foreground text-2xl font-semibold">
                            {getInitials(user.name)}
                        </AvatarFallback>
                    </Avatar>

                    <div className="min-w-0">
                        <h1 className="font-display text-foreground truncate text-3xl font-bold tracking-tight md:text-4xl">
                            {user.name}
                        </h1>
                        <div className="text-muted-foreground mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">
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
                <p className="text-foreground/80 mt-6 text-sm leading-relaxed">
                    {user.bio}
                </p>
            )}
        </section>
    );
}
