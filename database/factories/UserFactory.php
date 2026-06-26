<?php

namespace Database\Factories;

use App\Enums\LinkedAccountProvider;
use App\Models\LinkedAccount;
use App\Models\User;
use App\Support\MockTronAddress;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'username' => Str::slug(fake()->unique()->userName()),
            'bio' => fake()->boolean(30) ? fake()->realText(120) : null,
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'tron_address' => MockTronAddress::generate(),
            // `is_active_mode` is intentionally omitted — falls through to the
            // column default (`false`). Production new users start inactive
            // and must explicitly opt in via the toggle on /listings/mine.
            // Tests + seeders that need marketplace-visible listings chain
            // `->active()` on this factory (or use `ListingFactory`, which
            // auto-creates an active owner so its default makes a visible
            // listing).
        ];
    }

    /**
     * Indicate that the user is in Active Mode — their Open listings are
     * visible on the public marketplace + profile. Most listing-creation
     * tests need this; seeders use it for the marketplace dataset.
     */
    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active_mode' => true,
        ]);
    }

    /**
     * Indicate that the user is in Inactive Mode — their Open listings
     * are hidden from public surfaces. Redundant with the column default
     * but kept for readability: a test calling `->inactive()` is clearly
     * exercising the inactive branch.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active_mode' => false,
        ]);
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Indicate that the model has two-factor authentication configured.
     */
    public function withTwoFactor(): static
    {
        return $this->state(fn (array $attributes) => [
            'two_factor_secret' => encrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1'])),
            'two_factor_confirmed_at' => now(),
        ]);
    }

    /**
     * Mark the user as having a verified Lichess account by inserting a
     * `LinkedAccount` row after creation. `(provider, username)` is unique
     * at the DB level, so callers needing multiple Lichess-verified users
     * in one test should pass distinct usernames.
     */
    public function withLichess(?string $username = null): static
    {
        return $this->afterCreating(function (User $user) use ($username) {
            LinkedAccount::create([
                'user_id' => $user->id,
                'provider' => LinkedAccountProvider::Lichess->value,
                'username' => $username ?? Str::slug(fake()->unique()->userName()),
                'verified_at' => now(),
            ]);
        });
    }

    /**
     * Mark the user as having a verified chess.com account. Symmetric to
     * `withLichess()` for the same per-test uniqueness reason.
     */
    public function withChessCom(?string $username = null): static
    {
        return $this->afterCreating(function (User $user) use ($username) {
            LinkedAccount::create([
                'user_id' => $user->id,
                'provider' => LinkedAccountProvider::ChessCom->value,
                'username' => $username ?? Str::slug(fake()->unique()->userName()),
                'verified_at' => now(),
            ]);
        });
    }

    /**
     * Mark the user as having a verified FACEIT account (M15). Populates
     * `provider_user_id` (FACEIT guid) and `skill_rating` (Faceit ELO)
     * alongside `username` — these columns are NULL for chess providers
     * but expected for non-chess adapters. Used by Phase 1 snapshot tests
     * until the Phase 2 OAuth callback writes real values.
     */
    public function withFaceit(?string $username = null, ?string $providerUserId = null, ?int $skillRating = null, ?CarbonInterface $syncedAt = null): static
    {
        return $this->afterCreating(function (User $user) use ($username, $providerUserId, $skillRating, $syncedAt) {
            LinkedAccount::create([
                'user_id' => $user->id,
                'provider' => LinkedAccountProvider::Faceit->value,
                'username' => $username ?? Str::slug(fake()->unique()->userName()),
                'provider_user_id' => $providerUserId ?? (string) Str::uuid(),
                'skill_rating' => $skillRating ?? fake()->numberBetween(800, 2200),
                // M41 P1 — default to a fresh sync so existing factory-built
                // FACEIT users aren't treated as stale by the refresh gate;
                // pass an older time to exercise the staleness path.
                'skill_rating_synced_at' => $syncedAt ?? now(),
                'verified_at' => now(),
            ]);
        });
    }

    /**
     * Assign the Spatie `admin` role after creation. Creates the role if
     * it doesn't exist yet (RefreshDatabase tests drop the roles table
     * between cases). Used by Filament panel tests + any other admin-only
     * surface.
     */
    public function admin(): static
    {
        return $this
            ->state(fn () => [
                // M30 P3 — admin-panel gate requires `two_factor_confirmed_at`
                // to be set. Factory stamps it by default so existing tests
                // that do `User::factory()->admin()->create()` keep reaching
                // `/admin` without needing per-test 2FA wiring. To exercise
                // the gate's redirect path, override with
                // `->create(['two_factor_confirmed_at' => null])`.
                'two_factor_confirmed_at' => now(),
            ])
            ->afterCreating(function (User $user) {
                $role = Role::firstOrCreate([
                    'name' => 'admin',
                    'guard_name' => 'web',
                ]);

                $user->assignRole($role);
            });
    }
}
