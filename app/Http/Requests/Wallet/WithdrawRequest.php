<?php

namespace App\Http\Requests\Wallet;

use App\Services\KycGate;
use App\Services\Wallet;
use App\Services\WithdrawalTwoFactor;
use App\Services\WithdrawalVelocity;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Validates a withdrawal request: target TRC20 address + amount.
 *
 * This is the friendly 422 layer only. `Withdrawals::request()` re-checks the
 * available balance under a row lock, because validation and the debit aren't
 * atomic together — that locked re-check is the real guard.
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
            // Presence only — correctness is checked in `after()`, so a wrong
            // code reports as "that code didn't work" rather than a shape error.
            'two_factor_code' => [
                WithdrawalTwoFactor::enabled() ? 'required' : 'nullable',
                'string',
            ],
        ];
    }

    /**
     * Foreseeable refusals, surfaced as a 422 on `amount` rather than letting
     * `Withdrawals::request` throw — the service guards are the backstop, but an
     * uncaught exception would 500 on a perfectly ordinary user action.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $user = $this->user();

                if ($user === null) {
                    return;
                }

                if ($user->isFrozen()) {
                    $validator->errors()->add('amount', __(
                        'Your account is under review and withdrawals are paused. Contact support if you think this is a mistake.'
                    ));

                    return;
                }

                // Off by default (M9 Phase 0c). Reported only once the amount
                // itself is otherwise valid, so a typo'd amount doesn't
                // announce a verification requirement that may not apply.
                if ($validator->errors()->has('amount')) {
                    return;
                }

                $amount = $this->input('amount');

                if (! is_numeric($amount)) {
                    return;
                }

                if (KycGate::requiresVerification($user, (string) $amount)) {
                    $validator->errors()->add('amount', __(
                        'Withdrawals above :threshold USDT need a verified account. Contact support to verify yours.',
                        ['threshold' => (string) config('stakly.kyc_threshold')],
                    ));

                    return;
                }

                if (WithdrawalVelocity::exceedsDailyLimit($user, (string) $amount)) {
                    $validator->errors()->add('amount', __(
                        'Daily withdrawal limit reached. You can withdraw :remaining USDT more in the next 24 hours.',
                        ['remaining' => WithdrawalVelocity::remainingToday($user)],
                    ));
                }
            },
            // 2FA step-up (M9 Phase 0d). Checked last and reported on its own
            // field so it never masks an amount problem the player must fix
            // anyway — no point burning a one-shot code on a request that was
            // going to fail regardless.
            function (Validator $validator): void {
                $user = $this->user();

                if ($user === null || ! WithdrawalTwoFactor::required($user)) {
                    return;
                }

                if (! WithdrawalTwoFactor::hasEnrolled($user)) {
                    $validator->errors()->add('two_factor_code', __(
                        'Set up two-factor authentication before withdrawing. You can enable it in security settings.'
                    ));

                    return;
                }

                if ($validator->errors()->has('amount') || $validator->errors()->has('address')) {
                    return;
                }

                if (! WithdrawalTwoFactor::verify($user, $this->input('two_factor_code'))) {
                    $validator->errors()->add('two_factor_code', __(
                        'That code didn\'t work. Check your authenticator app and try the current code.'
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
