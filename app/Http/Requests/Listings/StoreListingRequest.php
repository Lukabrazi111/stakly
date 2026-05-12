<?php

namespace App\Http\Requests\Listings;

use App\Enums\Game;
use App\Enums\TimeControl;
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
            'stake_amount' => ['required', 'numeric', 'min:1', 'max:100000'],
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
}
