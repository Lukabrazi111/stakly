<?php

namespace App\Http\Requests\GameMatch;

use App\Models\Listing;
use App\Services\Wallet;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a Take request. The body is empty — the listing comes from the
 * route and the taker comes from auth. The only authoritative pre-check is
 * "user has balance ≥ stake."
 *
 * State checks (listing is Open, user is not the creator, listing not expired)
 * happen inside the controller's lockForUpdate transaction — they need to be
 * race-safe, which a FormRequest can't guarantee. The defensive `Wallet::hold`
 * still throws `InsufficientBalanceException` for the concurrent-tab race
 * where balance dropped between this pre-check and the row-locked re-check;
 * the controller catches it as a `ValidationException`.
 *
 * Auth + email verification are enforced at the route middleware layer; this
 * request trusts both have already been checked.
 */
class TakeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // No body fields — the route param + auth user are everything we need.
        return [];
    }

    /**
     * Pre-check the taker's balance against the listing's stake. Same shape
     * as `StoreListingRequest::stakeWithinBalance`, just sourced from the
     * route-bound listing instead of a request field.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $listing = $this->route('listing');
            $user = $this->user();

            if (! $listing instanceof Listing || $user === null) {
                return;
            }

            $balance = Wallet::balanceFor($user);
            $stake = (string) $listing->stake_amount;

            if (bccomp($stake, $balance, 6) > 0) {
                $validator->errors()->add(
                    'amount',
                    __('Stake exceeds your available balance ($:balance USDT).', [
                        'balance' => number_format((float) $balance, 2, '.', ''),
                    ]),
                );
            }
        });
    }
}
