<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'username', 'bio', 'email', 'password', 'tron_address'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable, TwoFactorAuthenticatable;

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
}
