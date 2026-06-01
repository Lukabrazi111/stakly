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
use Illuminate\Database\Eloquent\Relations\MorphMany;
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
     * Computed avatar URLs flow into Inertia's shared `auth.user` and
     * `UserProfileResource` without controller plumbing. Null when no avatar
     * uploaded; the frontend falls back to a gradient-initials placeholder.
     *
     * @var list<string>
     */
    protected $appends = ['avatar_url', 'avatar_thumb_url'];

    /**
     * Filament panel access gate. Only `admin`-roled users reach `/admin/*`.
     * The platform user (`is_platform = true`) is also blocked — same posture
     * as the wallet routes' `is_platform → 403` gate, defense in depth.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        if ($this->is_platform) {
            return false;
        }

        return $this->hasRole('admin');
    }

    /**
     * Route model binding on `username` so `/users/{user}` resolves via the
     * public handle. Username is derived at registration and immutable.
     */
    public function getRouteKeyName(): string
    {
        return 'username';
    }

    /**
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
            'notifications_last_seen_at' => 'datetime',
        ];
    }

    /**
     * Invariant: `SUM(wallet_transactions.amount) == users.usdt_balance` always.
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
     * Taker side only. Creator side is reached via `$user->listings`; combined
     * "all my matches" queries use `GameMatch::scopeForParticipant` instead.
     */
    public function gameMatchesAsTaker(): HasMany
    {
        return $this->hasMany(GameMatch::class, 'taker_user_id');
    }

    /**
     * Verified external game-account links. One row per (user_id, provider).
     * Callers reading the legacy `chess_com_*` / `lichess_*` accessors should
     * `->load('linkedAccounts')` first to avoid N+1.
     */
    public function linkedAccounts(): HasMany
    {
        return $this->hasMany(LinkedAccount::class);
    }

    public function pendingVerification(): HasOne
    {
        return $this->hasOne(PendingVerification::class);
    }

    // Excludes Filament admin rows — only PlayerNotification payloads set event_type.
    public function playerNotifications(): MorphMany
    {
        return $this->notifications()->whereNotNull('data->event_type');
    }

    /**
     * Gates both sides of marketplace participation (take-gate + create-gate).
     * Permissive — one link unlocks both — because today every listing is
     * chess. Read from the loaded `linkedAccounts` collection when present
     * (Inertia shared-data hot path); callers in tight loops should
     * `->load('linkedAccounts')` first.
     */
    public function hasVerifiedChessLink(): bool
    {
        if ($this->relationLoaded('linkedAccounts')) {
            return $this->linkedAccounts->isNotEmpty();
        }

        return $this->linkedAccounts()->exists();
    }

    /**
     * Backwards-compat accessor — reads off `linkedAccounts`. Eager-load
     * `linkedAccounts` first to keep this query-free.
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
     * `singleFile()` deletes + inserts atomically so a new avatar replaces
     * the old. MIME types mirror `ProfileUpdateRequest` (defense in depth).
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('profile-avatar')
            ->singleFile()
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp']);
    }

    /**
     * `nonQueued()` runs conversions inline — the source is pre-cropped to
     * ~512px by `react-image-crop` so the resize cost is trivial, and a
     * still-pending conversion URL would 404 momentarily on the next render.
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

    protected function avatarUrl(): Attribute
    {
        return Attribute::get(function (): ?string {
            $media = $this->getFirstMedia('profile-avatar');

            return $media?->getUrl('main');
        });
    }

    protected function avatarThumbUrl(): Attribute
    {
        return Attribute::get(function (): ?string {
            $media = $this->getFirstMedia('profile-avatar');

            return $media?->getUrl('thumb');
        });
    }
}
