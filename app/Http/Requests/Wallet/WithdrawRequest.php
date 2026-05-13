<?php

namespace App\Http\Requests\Wallet;

use App\Services\Wallet;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a withdrawal request: target TRC20 address + amount.
 *
 * In v1 the controller short-circuits before any ledger write happens (see
 * milestones.md M7 locked decisions, "Withdrawal flow = Option B"), but the
 * validation is full-strength so the form's 422 paths can be exercised today
 * without rework when the worker lands at pre-launch.
 *
 * Auth + email verification are enforced at the route layer.
 */
class WithdrawRequest extends FormRequest
{
    /**
     * Minimum withdrawal in USDT. Tron's TRC20 transfer typically burns ~3–7
     * USDT in energy/bandwidth fees; 10 USDT is a comfortable floor that keeps
     * users from sending dust transactions where most of the value goes to
     * gas. Pre-launch this becomes a config value tied to live gas pricing.
     */
    public const MIN_WITHDRAWAL = 10;

    /**
     * Tron address shape: literal 'T' + 33 base58 characters (`0`, `O`, `I`,
     * `l` excluded). This is a structural check only — it doesn't verify the
     * address checksum byte. The withdrawal worker at pre-launch performs the
     * full base58check decode before broadcasting any tx.
     */
    public const TRC20_REGEX = '/^T[1-9A-HJ-NP-Za-km-z]{33}$/';

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'address' => ['required', 'string', 'regex:'.self::TRC20_REGEX],
            // `decimal:0,2` pins fractional digits to ≤ 2 — matches the listings
            // precision convention. Internal ledger is scale 6, but anything
            // a human types is two-decimal cents.
            'amount' => [
                'required', 'numeric', 'decimal:0,2',
                'min:'.self::MIN_WITHDRAWAL,
                'max:100000',
                $this->amountWithinBalance(),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'address.regex' => __('Enter a valid TRC20 (Tron) USDT address.'),
            'amount.min' => __('Minimum withdrawal is :min USDT.', ['min' => self::MIN_WITHDRAWAL]),
        ];
    }

    /**
     * Closure rule: amount must not exceed the user's current balance.
     * Uses `bccomp` at scale 6 — same precision as the Wallet service — so
     * we never lose a sub-cent of headroom to float rounding. Mirrors
     * `StoreListingRequest::stakeWithinBalance`.
     */
    private function amountWithinBalance(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $user = $this->user();

            if ($user === null) {
                return;
            }

            $balance = Wallet::balanceFor($user);

            if (bccomp((string) $value, $balance, 6) > 0) {
                $fail(__('Withdrawal exceeds your available balance ($:balance USDT).', [
                    'balance' => number_format((float) $balance, 2, '.', ''),
                ]));
            }
        };
    }
}
