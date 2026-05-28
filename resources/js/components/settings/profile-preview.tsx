import { Link } from '@inertiajs/react';
import { ArrowUpRight, Eye } from 'lucide-react';
import { VerificationChip } from '@/components/profile/verification-chip';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { useInitials } from '@/hooks/use-initials';

interface Props {
    name: string;
    username: string;
    bio: string;
    /**
     * The avatar URL to display. Pass the staged-blob preview URL when the
     * user has picked a new avatar but hasn't saved yet, otherwise the
     * persisted `user.avatar_url`. Undefined falls back to gradient initials.
     */
    avatarSrc: string | undefined;
    /** ISO datetime — formatted to "Joined {Mon YYYY}" in the chip strip. */
    joinedAt: string;
    linkedAccounts: ReadonlyArray<{
        provider: 'chess_com' | 'lichess';
        username: string;
    }>;
    /** Absolute or relative URL of the user's own public profile page. */
    profileUrl: string;
}

/**
 * Live preview of the user's public profile, shown above the settings/profile
 * form so the user can see how name + avatar + bio changes will land on their
 * actual `/users/{username}` page as they type. Mirrors the layout of
 * `ProfileHeader` (the real profile hero) but lighter — no Edit button,
 * no trust-data hookup, just identity.
 *
 * Updates live: `name`, `bio`, and `avatarSrc` come from the form's draft
 * state on every keystroke / file pick. `username`, `joinedAt`, and
 * `linkedAccounts` are read-only context (none of them are editable here).
 */
export function ProfilePreview({
    name,
    username,
    bio,
    avatarSrc,
    joinedAt,
    linkedAccounts,
    profileUrl,
}: Props) {
    const getInitials = useInitials();

    const joinedLabel = new Intl.DateTimeFormat('en-US', {
        month: 'short',
        year: 'numeric',
    }).format(new Date(joinedAt));

    const trimmedName = name.trim() || 'Your name';
    const trimmedBio = bio.trim();

    return (
        <section
            aria-label="Public profile preview"
            className="rounded-2xl border border-border/60 bg-card p-6"
        >
            <header className="mb-4 flex items-center justify-between gap-3">
                <span className="inline-flex items-center gap-1.5 text-[11px] font-medium tracking-widest text-muted-foreground uppercase">
                    <Eye className="size-3.5" aria-hidden="true" />
                    Preview
                </span>
                <Link
                    href={profileUrl}
                    className="inline-flex items-center gap-1 text-xs font-medium text-primary transition-colors hover:text-primary/80"
                >
                    View public profile
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
                    Joined {joinedLabel}
                </span>
            </div>

            {trimmedBio ? (
                <p className="mt-4 max-w-prose text-sm leading-relaxed whitespace-pre-line text-foreground/90">
                    {trimmedBio}
                </p>
            ) : (
                <p className="mt-4 text-sm text-muted-foreground italic">
                    Bio is empty — add one to tell other players a bit about
                    yourself.
                </p>
            )}
        </section>
    );
}
