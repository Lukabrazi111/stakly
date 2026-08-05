<?php

namespace App\Models;

use App\Enums\Game;
use App\Enums\KycStatus;
use App\Enums\LinkedAccountProvider;
use App\Enums\MatchStatus;
use App\Notifications\PlayerNotification;
use Carbon\CarbonImmutable;
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
     * Single source of truth for "is this user an admin?" — consumed by the
     * Filament panel gate (`canAccessPanel`) and the Horizon dashboard gate
     * (`viewHorizon` in `HorizonServiceProvider`). The platform user
     * (`is_platform = true`) is excluded even if somehow admin-roled — same
     * posture as the wallet routes' `is_platform → 403` gate, defense in depth.
     */
    public function isAdmin(): bool
    {
        return ! $this->is_platform && $this->hasRole('admin');
    }

    /**
     * Filament panel access gate. Only `admin`-roled users reach `/admin/*`.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->isAdmin();
    }

    public const USERNAME_CHANGE_COOLDOWN_DAYS = 30;

    public const USERNAME_RESERVATION_DAYS = 30;

    /**
     * System handles + route-segment names a `/users/{x}` URL could collide
     * with. Shared by registration (`CreateNewUser`) and the rename validator.
     *
     * @var list<string>
     */
    public const RESERVED_USERNAMES = [
        'admin', 'administrator', 'staff', 'support', 'help',
        'stakly', 'platform', 'system', 'root', 'null',
        'listings', 'settings', 'login', 'register', 'logout',
        'wallet', 'match', 'matches', 'api', 'users', 'user',
    ];

    /**
     * Mirrors the column default so a freshly-created instance carries the
     * status in memory too — without this `$user->kyc_status` is null until the
     * model is re-read, and `KycGate` would be dereferencing null on a money path.
     *
     * @var array<string, string>
     */
    protected $attributes = [
        'kyc_status' => KycStatus::Unverified->value,
    ];

    /**
     * Route model binding on `username` so `/users/{user}` resolves via the
     * public handle. Renames are gated by `canChangeUsername()` and old
     * handles stay reserved via `username_history` for
     * `USERNAME_RESERVATION_DAYS` after release.
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
            'notification_sound' => 'string',
            'username_changed_at' => 'immutable_datetime',
            'banned_at' => 'immutable_datetime',
            'frozen_at' => 'immutable_datetime',
            'kyc_status' => KycStatus::class,
            'kyc_verified_at' => 'immutable_datetime',
        ];
    }

    public function isBanned(): bool
    {
        return $this->banned_at !== null;
    }

    /**
     * Money-level freeze. Blocks debits (`Wallet::withdraw` / `Wallet::hold`)
     * while leaving credits flowing — see the `frozen_at` column comment.
     */
    public function isFrozen(): bool
    {
        return $this->frozen_at !== null;
    }

    /**
     * Authz hook read by `stechstudio/filament-impersonate` to decide whether
     * THIS user can start an impersonation. Admin role only; platform user is
     * excluded as defense-in-depth (it shouldn't carry the admin role, but the
     * guard is cheap).
     */
    public function canImpersonate(): bool
    {
        return ! $this->is_platform && $this->hasRole('admin');
    }

    /**
     * Authz hook read by `stechstudio/filament-impersonate` to decide whether
     * THIS user can be impersonated. Platform user, banned users, and any
     * `is_platform` row are off-limits. Self-impersonation is already blocked
     * inside the package's own `canImpersonate()` check on the action.
     */
    public function canBeImpersonated(): bool
    {
        return ! $this->is_platform && ! $this->isBanned();
    }

    /**
     * Invariant: `SUM(wallet_transactions.amount) == users.usdt_balance` always.
     */
    public function walletTransactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class);
    }

    /**
     * Cash-out requests (M9 Phase 0b). The rows here are a lifecycle record;
     * the money itself lives in `walletTransactions`.
     */
    public function withdrawals(): HasMany
    {
        return $this->hasMany(Withdrawal::class);
    }

    public function listings(): HasMany
    {
        return $this->hasMany(Listing::class);
    }

    /**
     * M34 — the user's current team-play lobby participation, if any. Used to
     * enforce the global single-active-lobby rule.
     *
     * "Active" = a live (not-kicked) participant row on a listing whose lobby
     * is still in flight (`recruiting`, `ready_checking`, or `locked`) AND
     * — for the `locked` branch — whose underlying match has not yet hit a
     * terminal status. `lobby_state` stays `locked` even after Settled /
     * ManualReview / Cancelled, so the lobby_state check alone would lock the
     * user out of joining new lobbies forever once a match finishes (M34 P7
     * follow-up bug).
     *
     * Terminal match statuses (Settled, ManualReview, Cancelled) release the
     * user. `Disputed` keeps them locked — the dispute is an active engagement
     * (evidence gathering in chat) where joining a parallel lobby would
     * fragment attention. `Pending` and `LobbyFilling` are obviously active.
     */
    public function activeLobbyParticipation(): ?LobbyParticipant
    {
        $terminalMatchStatuses = [
            MatchStatus::Settled->value,
            MatchStatus::ManualReview->value,
            MatchStatus::Cancelled->value,
        ];

        return LobbyParticipant::query()
            ->where('user_id', $this->id)
            ->live()
            ->whereHas('listing', fn ($q) => $q
                ->whereIn('lobby_state', ['recruiting', 'ready_checking', 'locked'])
                ->where(fn ($q) => $q
                    ->whereIn('lobby_state', ['recruiting', 'ready_checking'])
                    ->orWhereDoesntHave('gameMatch', fn ($m) => $m
                        ->whereIn('status', $terminalMatchStatuses))))
            ->first();
    }

    /**
     * M37 — is this user currently in an in-flight match for `$game`? Powers
     * the "one active match per game" rule: a chess match and a CS2 match at
     * once is fine, two of the same game is not. Team-aware (creator / taker /
     * live lobby roster); counts the in-progress set (Pending / Disputed /
     * ManualReview) for listings of that game. The username-rename blocker uses
     * a game-agnostic version instead — any in-flight match blocks a rename.
     */
    public function hasInFlightMatchForGame(Game $game): bool
    {
        return GameMatch::query()
            ->forRosterParticipant($this->id)
            ->whereIn('status', MatchStatus::inProgressValues())
            ->whereHas('listing', fn ($q) => $q->where('game', $game->value))
            ->exists();
    }

    /**
     * Taker side only. Creator side is reached via `$user->listings`; combined
     * "all my matches" queries use `GameMatch::scopeForParticipant` instead.
     */
    public function gameMatchesAsTaker(): HasMany
    {
        return $this->hasMany(GameMatch::class, 'taker_user_id');
    }

    public function usernameHistory(): HasMany
    {
        return $this->hasMany(UsernameHistory::class);
    }

    public function moderationLogs(): HasMany
    {
        return $this->hasMany(UserModerationLog::class);
    }

    /**
     * Latest `action = ban` row for this user — the source of truth for the
     * suspension reason rendered by the persistent banner + bell card. After
     * an unban this row stays in `user_moderation_logs` (append-only audit),
     * but the banner only reads it while `banned_at !== null`.
     */
    public function latestBanLog(): HasOne
    {
        return $this->hasOne(UserModerationLog::class)
            ->where('action', UserModerationLog::ACTION_BAN)
            ->latestOfMany();
    }

    public function canChangeUsername(): bool
    {
        return $this->usernameChangeBlockers() === [];
    }

    /**
     * Cooldown clock end (null when no cooldown blocker). UI uses this to
     * render the "available again in N days" hint without inferring the
     * window length client-side.
     */
    public function usernameChangeAvailableAt(): ?CarbonImmutable
    {
        if ($this->username_changed_at === null) {
            return null;
        }

        $available = $this->username_changed_at->addDays(self::USERNAME_CHANGE_COOLDOWN_DAYS);

        return $available->isFuture() ? $available : null;
    }

    /**
     * One reason per condition currently blocking a rename. Empty array =
     * allowed. Order is deliberate: banned first (it's terminal — every
     * other blocker is moot for a suspended account), then cooldown (has a
     * date), then in-flight match.
     *
     * @return list<'banned'|'cooldown'|'in_flight_match'>
     */
    public function usernameChangeBlockers(): array
    {
        $blockers = [];

        if ($this->isBanned()) {
            $blockers[] = 'banned';
        }

        if ($this->usernameChangeAvailableAt() !== null) {
            $blockers[] = 'cooldown';
        }

        $hasInFlightMatch = GameMatch::query()
            ->forParticipant($this->id)
            ->whereIn('status', [
                MatchStatus::LobbyFilling,
                MatchStatus::Pending,
                MatchStatus::Disputed,
                MatchStatus::ManualReview,
            ])
            ->exists();

        // M34: lobby participants who aren't creator OR placeholder-taker
        // (i.e. joiners on the opposing side) still count as in-flight. The
        // global helper hits live participations in `recruiting`,
        // `ready_checking`, or `locked` lobbies.
        $hasLobbyParticipation = $this->activeLobbyParticipation() !== null;

        if ($hasInFlightMatch || $hasLobbyParticipation) {
            $blockers[] = 'in_flight_match';
        }

        return $blockers;
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

    public function notificationPreferences(): HasMany
    {
        return $this->hasMany(NotificationPreference::class);
    }

    /**
     * @return array{in_app: bool, sound: bool, email: bool}
     */
    public function getNotificationPreference(string $eventType): array
    {
        $row = $this->relationLoaded('notificationPreferences')
            ? $this->notificationPreferences->firstWhere('event_type', $eventType)
            : $this->notificationPreferences()->where('event_type', $eventType)->first();

        if ($row === null) {
            return PlayerNotification::defaultPreference($eventType);
        }

        return [
            'in_app' => (bool) $row->in_app,
            'sound' => (bool) $row->sound,
            'email' => (bool) $row->email,
        ];
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

    public function isVerifiedOn(LinkedAccountProvider $provider): bool
    {
        return $this->linkedAccountFor($provider) !== null;
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
