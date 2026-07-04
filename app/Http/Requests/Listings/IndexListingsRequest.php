<?php

namespace App\Http\Requests\Listings;

use App\Enums\Game;
use App\Enums\TimeControl;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Spatie query-builder URL contract:
 *   /listings?filter[stake_max]=100&filter[time_control]=blitz,rapid&sort=highest_stake&page=2
 *
 * Every field is attacker-controlled. Malformed input redirects to a clean
 * `/listings` instead of 422 so a stale share-link lands on the unfiltered
 * marketplace.
 */
class IndexListingsRequest extends FormRequest
{
    public const SORTS = ['newest', 'highest_stake', 'lowest_stake', 'ending_soon'];

    public const FILTER_KEYS = [
        'game',
        'stake_min',
        'stake_max',
        'skill_min',
        'skill_max',
        'unrated',
        'time_control',
        'region',
        'language',
    ];

    protected $redirect = '/listings';

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalize `filter.time_control` to an array up-front so validation
     * rules can check each entry against the TimeControl enum.
     */
    protected function prepareForValidation(): void
    {
        $filter = (array) $this->input('filter', []);

        if (isset($filter['time_control']) && is_string($filter['time_control'])) {
            $filter['time_control'] = array_values(array_filter(
                array_map('trim', explode(',', $filter['time_control'])),
                fn (string $v) => $v !== '',
            ));

            $this->merge(['filter' => $filter]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'filter' => ['nullable', 'array:'.implode(',', self::FILTER_KEYS)],
            'filter.game' => ['nullable', 'string', Rule::enum(Game::class)],
            'filter.stake_min' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'filter.stake_max' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            // M41 P5: skill_min/max filter the CREATOR's verified rating as raw
            // Elo for BOTH games — chess Elo and FACEIT Elo (CS2 was revised off
            // the 1–10 level selector on 2026-06-28). The 0–3500 ceiling covers
            // both; see ListingController::applyRatingFilter().
            'filter.skill_min' => ['nullable', 'integer', 'min:0', 'max:3500'],
            'filter.skill_max' => ['nullable', 'integer', 'min:0', 'max:3500'],
            'filter.unrated' => ['nullable', 'boolean'],
            'filter.time_control' => ['nullable', 'array', 'max:'.count(TimeControl::cases())],
            'filter.time_control.*' => ['string', Rule::enum(TimeControl::class)],
            'filter.region' => ['nullable', 'string', 'max:50'],
            'filter.language' => ['nullable', 'string', 'max:50'],
            'sort' => ['nullable', 'string', Rule::in(self::SORTS)],
            'page' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ];
    }

    /**
     * @return array{game: string, stake_min: ?float, stake_max: ?float, skill_min: ?int, skill_max: ?int, unrated: bool, time_control: array<int, string>, region: ?string, language: ?string, sort: string}
     */
    public function filters(): array
    {
        $filter = (array) $this->input('filter', []);

        return [
            'game' => $filter['game'] ?? Game::Chess->value,
            'stake_min' => isset($filter['stake_min']) && $filter['stake_min'] !== '' ? (float) $filter['stake_min'] : null,
            'stake_max' => isset($filter['stake_max']) && $filter['stake_max'] !== '' ? (float) $filter['stake_max'] : null,
            'skill_min' => isset($filter['skill_min']) && $filter['skill_min'] !== '' ? (int) $filter['skill_min'] : null,
            'skill_max' => isset($filter['skill_max']) && $filter['skill_max'] !== '' ? (int) $filter['skill_max'] : null,
            'unrated' => filter_var($filter['unrated'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'time_control' => array_values((array) ($filter['time_control'] ?? [])),
            'region' => ! empty($filter['region']) ? (string) $filter['region'] : null,
            'language' => ! empty($filter['language']) ? (string) $filter['language'] : null,
            'sort' => $this->input('sort', 'newest'),
        ];
    }
}
