<?php

namespace App\Http\Requests\Listings;

use App\Enums\Game;
use App\Enums\LinkedAccountProvider;
use App\Enums\ListingStatus;
use App\Enums\TimeControl;
use App\Models\LobbyParticipant;
use App\Services\Wallet;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validates input for creating a new listing.
 *
 * Phase 1 (M4) — basic shape validation. Phase 7 adds the balance-check rule
 * (stake_amount ≤ current `usdt_balance`) so the form can give a nice 422
 * before `Wallet::hold` ever runs. The defensive `InsufficientBalanceException`
 * path still exists at the service layer for concurrent races.
 *
 * Auth + email verification are enforced at the route middleware layer; this
 * request trusts both have already been checked.
 */
class StoreListingRequest extends FormRequest
{
    public const DURATION_HOURS = [1, 6, 12, 24, 48, 72];

    public const REGIONS = ['Global', 'EU', 'NA', 'Asia', 'CIS', 'LATAM'];

    public const LANGUAGES = ['English', 'Russian', 'Spanish', 'German', 'Portuguese'];

    /**
     * Maximum number of active (Open) listings a single user can hold at
     * once. Set to 2; bump later if friction shows up (each chess listing is
     * a single time control now — Bullet / Blitz / Rapid — so a player
     * wanting several formats up at once hits the cap fast).
     * Taken / Expired / Cancelled don't count toward the cap. Global Active
     * Mode (also Phase 6.5) is an orthogonal visibility toggle, not a count
     * modifier — Inactive listings still count.
     */
    public const MAX_ACTIVE_LISTINGS = 2;

    /**
     * Minimum stake in USDT, from `config('stakly.min_stake')`.
     *
     * A TRC20 payout burns real gas, so a pot small enough that the rake is
     * dwarfed by settlement cost isn't worth running. Config-backed so the
     * floor moves without touching this rule.
     */
    public static function minStake(): string
    {
        return (string) config('stakly.min_stake');
    }

    public function authorize(): bool
    {
        return true;
    }

    /**
     * The form sends `language: []` when the user selects no languages (the
     * multi-toggle's empty state). Map that to null so we store the semantic
     * "no restriction" rather than an empty array — the resource + frontend
     * already model null as "any language welcome".
     */
    protected function prepareForValidation(): void
    {
        if (is_array($this->input('language')) && empty($this->input('language'))) {
            $this->merge(['language' => null]);
        }

        // Default team_size to 1 when missing — keeps the existing chess +
        // legacy CS2 1v1 forms working without a payload change. Team-play
        // listings (M34) explicitly send team_size > 1.
        if ($this->input('team_size') === null) {
            $this->merge(['team_size' => 1]);
        }

        // Default is_public to true. Frontend sends `false` explicitly when
        // the creator picks "private (invite link only)".
        if ($this->input('is_public') === null) {
            $this->merge(['is_public' => true]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'game' => ['required', 'string', Rule::enum(Game::class)],
            // The provider the match must be played on (M8 Phase 5 Slice B).
            // The create-gate (`CreateListingAction`) re-checks that the
            // creator has the picked platform verified — defense in depth on
            // top of this enum-membership check.
            'platform' => ['required', 'string', Rule::enum(LinkedAccountProvider::class)],
            // `decimal:0,2` caps fractional digits to 2 — matches the
            // `decimal(12, 2)` listings column. Without this, a stake of
            // `100.456` would be held at full ledger precision (-100.456000)
            // but stored on the listing as 100.46, causing a 0.004 over-refund
            // on cancel. Pin the precision at the boundary.
            'stake_amount' => [
                'required', 'numeric', 'decimal:0,2',
                'min:'.self::minStake(),
                'max:100000',
                $this->stakeWithinBalance(),
            ],
            // `time_control` is chess-only and now a SINGLE value (M41 P3a:
            // one chess listing = one time control → one verified rating).
            // Required + enum-checked for chess; `prohibited` (not merely
            // nullable) for non-chess so a crafted request can't smuggle a
            // stray time control onto a CS2 / future-game listing — the
            // "non-chess ⇒ time_control IS NULL" invariant is enforced at the
            // trust boundary, not just by the frontend + action.
            'time_control' => [
                Rule::when(
                    fn () => $this->input('game') === Game::Chess->value,
                    ['required', 'string', Rule::enum(TimeControl::class)],
                    ['prohibited'],
                ),
            ],
            'region' => ['nullable', 'string', Rule::in(self::REGIONS)],
            'language' => ['nullable', 'array', 'max:'.count(self::LANGUAGES)],
            'language.*' => ['string', Rule::in(self::LANGUAGES), 'distinct'],
            'duration_hours' => ['required', 'integer', Rule::in(self::DURATION_HOURS)],

            // M34 — team play + lobbies. `team_size` defaults to 1 (chess +
            // legacy 1v1 flow). Per-game allowed sizes enforced in
            // `withValidator()` against `Game::allowedTeamSizes()`.
            'team_size' => ['required', 'integer', 'min:1', 'max:10'],
            // Required only when team_size > 1. The creator picks their slot
            // side at listing creation — joiners pick at join time.
            'creator_side' => [
                Rule::when(
                    fn () => (int) $this->input('team_size') > 1,
                    ['required', 'string', Rule::in([LobbyParticipant::SIDE_A, LobbyParticipant::SIDE_B])],
                    ['nullable'],
                ),
            ],
            // M34 — private listings hide from the marketplace and surface
            // only via `/lobbies/{invite_token}`. Default true (public).
            'is_public' => ['required', 'boolean'],
        ];
    }

    /**
     * Defense-in-depth check for the max-active-listings cap. The frontend
     * disables the submit button when the user is at cap (see
     * `ListingController::create`), but a concurrent submit from a stale
     * tab still needs to be rejected server-side. Error attaches to a
     * non-field key so the React form can surface it as a banner rather
     * than inline on `stake_amount`.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $user = $this->user();

            if ($user === null) {
                return;
            }

            $activeCount = $user->listings()
                ->where('status', ListingStatus::Open)
                ->count();

            if ($activeCount >= self::MAX_ACTIVE_LISTINGS) {
                $validator->errors()->add(
                    'active_listings_cap',
                    __('You already have :count active listings — the maximum allowed. Cancel one (or wait for it to settle / expire) before creating another.', [
                        'count' => $activeCount,
                    ]),
                );
            }

            // M15 Phase 3 — platform must belong to the game's required
            // providers. CS2 listings can't post to chess.com, chess listings
            // can't post to FACEIT, etc. Frontend gates the picker in Slice 2;
            // server check covers stale tabs and crafted requests.
            $gameValue = $this->input('game');
            $platformValue = $this->input('platform');
            $game = is_string($gameValue) ? Game::tryFrom($gameValue) : null;
            $platform = is_string($platformValue) ? LinkedAccountProvider::tryFrom($platformValue) : null;

            if ($game !== null && $platform !== null
                && ! in_array($platform, $game->requiredProviders(), true)
            ) {
                $validator->errors()->add(
                    'platform',
                    __('The :platform platform isn\'t valid for :game listings.', [
                        'platform' => $platform->displayName(),
                        'game' => $game->displayName(),
                    ]),
                );
            }

            // M34 — team_size must be in the game's allowed set. Chess = [1]
            // only, CS2 = [1, 5], Dota2 = [1].
            $teamSize = (int) $this->input('team_size', 1);

            if ($game !== null && ! in_array($teamSize, $game->allowedTeamSizes(), true)) {
                $allowedList = implode(', ', $game->allowedTeamSizes());

                $validator->errors()->add(
                    'team_size',
                    __(':game listings only support team_size :allowed (got :got).', [
                        'game' => $game->displayName(),
                        'allowed' => $allowedList,
                        'got' => $teamSize,
                    ]),
                );
            }
        });
    }

    /**
     * Closure rule: stake must not exceed the user's current USDT balance.
     *
     * Uses `bccomp` at scale 6 — the same precision the Wallet service runs
     * at — so we never lose a sub-cent of headroom to float rounding when
     * the user's balance is, say, "99.999999" and they try to stake "100".
     *
     * This is a soft pre-check. `Wallet::hold` re-validates under a row lock
     * at commit time (the controller catches `InsufficientBalanceException`
     * for the concurrent-race case where balance dropped between request and
     * commit).
     */
    private function stakeWithinBalance(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            $user = $this->user();

            if ($user === null) {
                return;
            }

            // Wallet::balanceFor does `$user->fresh()->usdt_balance` — bypasses
            // any stale in-memory User instance. Critical for tests using
            // `actingAs($user)` after a deposit, and harmless in production.
            $balance = Wallet::balanceFor($user);

            if (bccomp((string) $value, $balance, 6) > 0) {
                $fail(__('Stake exceeds your available balance ($:balance USDT).', [
                    'balance' => number_format((float) $balance, 2, '.', ''),
                ]));
            }
        };
    }
}
