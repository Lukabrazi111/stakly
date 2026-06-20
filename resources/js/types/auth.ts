export type User = {
    id: number;
    name: string;
    username: string;
    email: string;
    bio: string | null;
    // M18 Phase 1 — uploaded avatar URLs served from the `public` disk via
    // Spatie media library. Null when the user hasn't uploaded an avatar.
    // `avatar_url` is 512×512 (header / settings), `avatar_thumb_url` is
    // 128×128 (chat bubbles / listing rows). Frontend falls back to a
    // gradient-initials placeholder via `useInitials()` when null.
    avatar_url: string | null;
    avatar_thumb_url: string | null;
    email_verified_at: string | null;
    two_factor_enabled?: boolean;
    // Spendable USDT balance. Float, not string — converted at the Inertia
    // middleware boundary (`HandleInertiaRequests`). Updates after every
    // navigation since auth.user is re-shared on each request.
    usdt_balance: number;
    // Global "Active Mode" flag (M6 Phase 6.5). When false, ALL the user's
    // Open listings are hidden from the marketplace + public profile views.
    // Toggled via /listings/mine page. Default true.
    is_active_mode: boolean;
    // True iff the user has at least one verified chess provider account
    // (chess.com OR Lichess). Gates marketplace participation: a `false`
    // here disables the Take CTA on listings and the Create CTA on the
    // listings page, both with a CTA to /settings/linked-accounts. Server
    // re-checks via `TakeListingAction` / `CreateListingAction` — frontend
    // disable is convenience UX, not the only enforcement layer.
    has_chess_link: boolean;
    // The verified chess providers the user has linked, ordered to match
    // `App\Enums\LinkedAccountProvider`. Empty array iff `has_chess_link`
    // is false. Used by the listing detail page to render
    // platform-specific disabled-Take copy ("Link Lichess to take" vs
    // "Link chess.com to take") and by the create form to show/hide the
    // platform picker.
    linked_platforms: Array<'chess_com' | 'lichess'>;
    // M18 — the raw linked-account rows serialized by `$user->toArray()`
    // when the `linkedAccounts` relation is eager-loaded (which the Inertia
    // middleware does on every request — see `HandleInertiaRequests::share`).
    // Optional because callers that don't need it shouldn't have to think
    // about it, but it's reliably present at runtime. Used by the settings
    // profile preview to render chess.com/Lichess `VerificationChip`s.
    linked_accounts?: Array<{
        provider: 'chess_com' | 'lichess';
        username: string;
        verified_at: string;
    }>;
    notifications_last_seen_at: string | null;
    unread_notifications_count: number;
    notification_sound: string;
    notification_sound_map: Record<string, boolean>;
    username_edit: {
        can_change: boolean;
        available_at: string | null;
        blockers: Array<'banned' | 'cooldown' | 'in_flight_match'>;
    };
    ban: {
        reason: string;
        banned_at: string;
    } | null;
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
};

export type Auth = {
    user: User | null;
};

export type TwoFactorSetupData = {
    svg: string;
    url: string;
};

export type TwoFactorSecretKey = {
    secretKey: string;
};
