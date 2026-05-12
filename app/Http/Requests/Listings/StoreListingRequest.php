<?php

namespace App\Http\Requests\Listings;

use App\Enums\Game;
use App\Enums\TimeControl;
use App\Services\Wallet;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'game' => ['required', 'string', Rule::enum(Game::class)],
            // `decimal:0,2` caps fractional digits to 2 — matches the
            // `decimal(12, 2)` listings column. Without this, a stake of
            // `100.456` would be held at full ledger precision (-100.456000)
            // but stored on the listing as 100.46, causing a 0.004 over-refund
            // on cancel. Pin the precision at the boundary.
            'stake_amount' => [
                'required', 'numeric', 'decimal:0,2', 'min:1', 'max:100000',
                $this->stakeWithinBalance(),
            ],
            'skill_min' => ['nullable', 'integer', 'min:0', 'max:3500'],
            'skill_max' => ['nullable', 'integer', 'min:0', 'max:3500', 'gte:skill_min'],
            'time_control' => ['required', 'array', 'min:1', 'max:'.count(TimeControl::cases())],
            'time_control.*' => ['string', Rule::enum(TimeControl::class), 'distinct'],
            'region' => ['nullable', 'string', Rule::in(self::REGIONS)],
            'language' => ['nullable', 'array', 'max:'.count(self::LANGUAGES)],
            'language.*' => ['string', Rule::in(self::LANGUAGES), 'distinct'],
            'duration_hours' => ['required', 'integer', Rule::in(self::DURATION_HOURS)],
        ];
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
