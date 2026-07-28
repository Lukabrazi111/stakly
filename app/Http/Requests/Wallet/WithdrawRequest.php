<?php

namespace App\Http\Requests\Wallet;

use App\Services\Wallet;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

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
     * Minimum withdrawal in USDT, from `config('stakly.min_withdrawal')`.
     *
     * Tron's TRC20 transfer typically burns ~3–7 USDT in energy/bandwidth
     * fees, so the floor exists to stop dust withdrawals where most of the
     * value goes to gas.
     */
    public static function minWithdrawal(): string
    {
        return (string) config('stakly.min_withdrawal');
    }

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
                'min:'.self::minWithdrawal(),
                'max:100000',
                $this->amountWithinBalance(),
            ],
        ];
    }

    /**
     * A frozen account can't move money out. Surfaced as a 422 on `amount`
     * rather than letting `Withdrawals::request` throw — the service guard is
     * the backstop, but an uncaught AccountFrozenException would 500 on a
     * perfectly foreseeable user action.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->user()?->isFrozen()) {
                    $validator->errors()->add('amount', __(
                        'Your account is under review and withdrawals are paused. Contact support if you think this is a mistake.'
                    ));
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'address.regex' => __('Enter a valid TRC20 (Tron) USDT address.'),
            'amount.min' => __('Minimum withdrawal is :min USDT.', ['min' => self::minWithdrawal()]),
        ];
    }

    /**
     * Closure rule: amount must not exceed the user's AVAILABLE balance —
     * total minus winnings still inside their insurance window (M9 Phase 0b).
     * Staking isn't gated this way; only money leaving the platform is.
     *
     * Uses `bccomp` at scale 6 — same precision as the Wallet service — so
     * we never lose a sub-cent of headroom to float rounding. Mirrors
     * `StoreListingRequest::stakeWithinBalance`.
     *
     * This is the friendly 422; `Withdrawals::request` re-checks under a row
     * lock, since validation and the debit aren't one atomic step.
     */
    private function amountWithinBalance(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $user = $this->user();

            if ($user === null) {
                return;
            }

            $available = Wallet::availableBalance($user);

            if (bccomp((string) $value, $available, 6) <= 0) {
                return;
            }

            $clearing = Wallet::unclearedBalance($user);

            // Distinguish "you don't have it" from "you have it but it's still
            // clearing" — otherwise the error reads as a bug to the player.
            if (bccomp($clearing, '0', 6) > 0) {
                $fail(__('Withdrawal exceeds your available balance ($:available USDT). $:clearing of your winnings is still clearing.', [
                    'available' => number_format((float) $available, 2, '.', ''),
                    'clearing' => number_format((float) $clearing, 2, '.', ''),
                ]));

                return;
            }

            $fail(__('Withdrawal exceeds your available balance ($:balance USDT).', [
                'balance' => number_format((float) $available, 2, '.', ''),
            ]));
        };
    }
}
