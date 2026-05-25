<?php

namespace App\Models;

use App\Enums\LinkedAccountProvider;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\Permission\Traits\HasRoles;

#[Fillable([
    'name',
    'username',
    'bio',
    'email',
    'password',
    'tron_address',
    'is_active_mode',
])]
#[Hidden([
    'password',
    'two_factor_secret',
    'two_factor_recovery_codes',
    'remember_token',
])]
class User extends Authenticatable implements FilamentUser, HasMedia, MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, InteractsWithMedia, Notifiable, TwoFactorAuthenticatable;

    /**
     * Computed avatar URLs exposed via Eloquent's `toArray()` — flow into
     * Inertia's shared `auth.user` and `UserProfileResource` without any
     * controller plumbing. Null when the user hasn't uploaded an avatar yet;
     * the frontend falls back to a gradient-initials placeholder.
     *
     * @var list<string>
     */
    protected $appends = ['avatar_url', 'avatar_thumb_url'];

    /**
     * M12 — Filament panel access gate. Required by the `FilamentUser`
     * interface. Only users with the Spatie `admin` role can reach
     * `/admin/*` URLs; anyone else is redirected to the login page (or
     * 403 if already authenticated as a non-admin). The platform user
     * (`is_platform = true`) is also blocked — same posture as the
     * `is_platform → 403` gate on the wallet routes, defense in depth.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        if ($this->is_platform) {
            return false;
        }

        return $this->hasRole('admin');
    }

    /**
     * Route model binding uses `username` instead of `id`, so `/users/{user}`
     * resolves via the public handle. Username is derived at registration and
     * immutable in v1.
     */
    public function getRouteKeyName(): string
    {
        return 'username';
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'usdt_balance' => 'decimal:6',
            'is_platform' => 'boolean',
            'is_active_mode' => 'boolean',
        ];
    }

    /**
     * Append-only ledger entries belonging to this user. Invariant:
     * `SUM(wallet_transactions.amount) == users.usdt_balance` always.
     */
    public function walletTransactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class);
    }

    public function listings(): HasMany
    {
        return $this->hasMany(Listing::class);
    }

    /**
     * Matches where this user is the taker. The creator side is reached via
     * `$user->listings->map->gameMatch` — combined "all my matches" queries
     * use a scope on `GameMatch` (Phase 6) rather than a model relation.
     */
    public function gameMatchesAsTaker(): HasMany
    {
        return $this->hasMany(GameMatch::class, 'taker_user_id');
    }

    /**
     * Verified external game-account links (M18 Phase 3 prep — replaces the
     * inline `chess_com_*` / `lichess_*` columns). One row per (user_id,
     * provider) pair. Callers that read `chess_com_username` /
     * `lichess_username` / `chess_com_verified_at` / `lichess_verified_at`
     * via the legacy accessors below should eager-load this relation to
     * avoid N+1 (e.g. `$user->load('linkedAccounts')`).
     */
    public function linkedAccounts(): HasMany
    {
        return $this->hasMany(LinkedAccount::class);
    }

    /**
     * In-flight bio-code verification state (transient). At most one row
     * per user — the verification flow upserts on (user_id). Replaces the
     * inline `pending_verification_*` columns.
     */
    public function pendingVerification(): HasOne
    {
        return $this->hasOne(PendingVerification::class);
    }

    /**
     * Has the user verified at least one chess provider account? Gates both
     * sides of marketplace participation (M8 Phase 5 take-gate +
     * create-gate). Permissive — one link unlocks both create and take —
     * because today every listing is chess and any verified chess link is
     * sufficient to support evidence resolution. Phase 5's
     * `listings.platform` column tightens this to "verified on the
     * listing's specific platform."
     *
     * Reads from the loaded `linkedAccounts` collection when present
     * (avoids an extra query on Inertia shared-data hot path); falls back
     * to a relation query when not loaded. Callers that hit this in tight
     * loops should `->load('linkedAccounts')` first.
     */
    public function hasVerifiedChessLink(): bool
    {
        if ($this->relationLoaded('linkedAccounts')) {
            return $this->linkedAccounts->isNotEmpty();
        }

        return $this->linkedAccounts()->exists();
    }

    /**
     * Backwards-compat accessor — reads the chess.com username off the
     * `linkedAccounts` relation. External callers still write
     * `$user->chess_com_username` after the M18 normalisation refactor.
     * Eager-load `linkedAccounts` first to keep this query-free.
     */
    protected function chessComUsername(): Attribute
    {
        return Attribute::get(
            fn (): ?string => $this->linkedAccountFor(LinkedAccountProvider::ChessCom)?->username,
        );
    }

    protected function chessComVerifiedAt(): Attribute
    {
        return Attribute::get(
            fn () => $this->linkedAccountFor(LinkedAccountProvider::ChessCom)?->verified_at,
        );
    }

    protected function lichessUsername(): Attribute
    {
        return Attribute::get(
            fn (): ?string => $this->linkedAccountFor(LinkedAccountProvider::Lichess)?->username,
        );
    }

    protected function lichessVerifiedAt(): Attribute
    {
        return Attribute::get(
            fn () => $this->linkedAccountFor(LinkedAccountProvider::Lichess)?->verified_at,
        );
    }

    /**
     * Internal helper for the backwards-compat accessors. Reads from the
     * loaded collection when available, falls back to a one-shot query.
     */
    private function linkedAccountFor(LinkedAccountProvider $provider): ?LinkedAccount
    {
        if ($this->relationLoaded('linkedAccounts')) {
            return $this->linkedAccounts->firstWhere('provider', $provider);
        }

        return $this->linkedAccounts()
            ->where('provider', $provider)
            ->first();
    }

    /**
     * Single-file avatar collection (M18 Phase 1). Uploading a new avatar
     * replaces the previous file on disk — `singleFile()` handles the
     * delete + insert atomically. Accepted MIME types match the validation
     * rule on `ProfileUpdateRequest`; both layers enforce the same set so
     * a request can't sneak past one and trip the other.
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('profile-avatar')
            ->singleFile()
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp']);
    }

    /**
     * Two derived sizes:
     *   - `main` (512×512) — public profile + settings preview
     *   - `thumb` (128×128) — chat bubbles, listing rows, comment avatars
     *
     * `nonQueued()` runs conversions inline because (a) the source is
     * already cropped to a square ~512px by `react-image-crop` on the
     * client, so the resize cost is trivial, and (b) returning a 200 with
     * a still-pending conversion URL would 404 momentarily on the next
     * page render. Once we need a CDN + larger originals, move to a
     * queued worker.
     */
    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('main')
            ->fit(Fit::Crop, 512, 512)
            ->nonQueued()
            ->performOnCollections('profile-avatar');

        $this->addMediaConversion('thumb')
            ->fit(Fit::Crop, 128, 128)
            ->nonQueued()
            ->performOnCollections('profile-avatar');
    }

    /**
     * Public URL of the 512×512 avatar conversion. Null when the user has
     * not uploaded an avatar. Exposed via `$appends` so Inertia's shared
     * `auth.user` carries it without any controller plumbing.
     */
    protected function avatarUrl(): Attribute
    {
        return Attribute::get(function (): ?string {
            $media = $this->getFirstMedia('profile-avatar');

            return $media?->getUrl('main');
        });
    }

    /**
     * Public URL of the 128×128 avatar thumbnail. Used by dense lists
     * (chat bubbles, listing rows) where the larger conversion is
     * overkill. Null when no avatar uploaded.
     */
    protected function avatarThumbUrl(): Attribute
    {
        return Attribute::get(function (): ?string {
            $media = $this->getFirstMedia('profile-avatar');

            return $media?->getUrl('thumb');
        });
    }
}
