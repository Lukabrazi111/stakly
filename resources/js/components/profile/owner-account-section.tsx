import { ShareProfileButton } from '@/components/profile/share-profile-button';

interface Props {
    profileUrl: string;
    username: string;
}

/**
 * Owner-only "Your account" block below the public profile tabs. Visible
 * only when the viewer === the profile owner; the parent gates this on
 * `auth.user.id === user.id`.
 *
 * Visually demarcated with `bg-secondary` shading so it reads as a distinct
 * surface from the public-section cards (which use `bg-card`). Single-item
 * shell today (share button only) — future profile-surface management
 * controls slot in here without re-shaping the public sections.
 *
 * Notifications + Blacklist tabs originally planned for this section were
 * dropped 2026-05-27 (see milestones.md M19 "Not in M19"); those features
 * will own their own UI under `/settings/*` when M20 + M21 ship.
 */
export function OwnerAccountSection({ profileUrl, username }: Props) {
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
                    Your account
                </h2>
                <p className="mt-1 text-sm text-muted-foreground">
                    Only you can see this section.
                </p>
            </header>

            <ShareProfileButton profileUrl={profileUrl} username={username} />
        </section>
    );
}
