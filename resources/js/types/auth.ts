export type User = {
    id: number;
    name: string;
    username: string;
    email: string;
    avatar?: string;
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
