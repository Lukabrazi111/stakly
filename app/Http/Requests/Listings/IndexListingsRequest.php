<?php

namespace App\Http\Requests\Listings;

use App\Enums\Game;
use App\Enums\TimeControl;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates filter + sort + page query params for the public listings index.
 *
 * URL shape follows Spatie's query-builder convention:
 *   /listings?filter[stake_max]=100&filter[time_control]=blitz,rapid&sort=highest_stake&page=2
 *
 * Every field is attacker-controlled. Keep rules tight — drop or coerce
 * anything weird rather than throwing a 422 (the page should still render
 * for users who land via a slightly-malformed shared link).
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
        'time_control',
        'region',
        'language',
    ];

    /**
     * On validation failure, redirect to a clean `/listings` instead of
     * bouncing the user back where they came from. A stale share-link with
     * malformed query params should land on the unfiltered marketplace, not
     * a 422 error wall.
     */
    protected $redirect = '/listings';

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Spatie splits comma-separated filter values automatically, but we
     * normalize `filter.time_control` to an array up-front so our validation
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
            'filter.skill_min' => ['nullable', 'integer', 'min:0', 'max:3500'],
            'filter.skill_max' => ['nullable', 'integer', 'min:0', 'max:3500'],
            'filter.time_control' => ['nullable', 'array', 'max:'.count(TimeControl::cases())],
            'filter.time_control.*' => ['string', Rule::enum(TimeControl::class)],
            'filter.region' => ['nullable', 'string', 'max:50'],
            'filter.language' => ['nullable', 'string', 'max:50'],
            'sort' => ['nullable', 'string', Rule::in(self::SORTS)],
            'page' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ];
    }

    /**
     * Filters echoed back to the frontend so the UI can hydrate its state
     * from the URL. Reads from `filter[*]` (Spatie convention) but returns
     * the flat shape the React side already consumes.
     *
     * @return array{game: string, stake_min: ?float, stake_max: ?float, skill_min: ?int, skill_max: ?int, time_control: array<int, string>, region: ?string, language: ?string, sort: string}
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
            'time_control' => array_values((array) ($filter['time_control'] ?? [])),
            'region' => ! empty($filter['region']) ? (string) $filter['region'] : null,
            'language' => ! empty($filter['language']) ? (string) $filter['language'] : null,
            'sort' => $this->input('sort', 'newest'),
        ];
    }
}
