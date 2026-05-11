<?php

namespace App\Http\Requests\Listings;

use App\Enums\Game;
use App\Enums\TimeControl;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates filter + sort query params for the public listings index.
 *
 * Every field is attacker-controlled. Keep rules tight — drop or coerce
 * anything weird rather than throwing a 422 (the page should still render
 * for users who land via a slightly-malformed shared link).
 */
class IndexListingsRequest extends FormRequest
{
    public const SORTS = ['newest', 'highest_stake', 'lowest_stake', 'ending_soon'];

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
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'game' => ['nullable', 'string', Rule::enum(Game::class)],
            'stake_min' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'stake_max' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'skill_min' => ['nullable', 'integer', 'min:0', 'max:3500'],
            'skill_max' => ['nullable', 'integer', 'min:0', 'max:3500'],
            'time_control' => ['nullable', 'array', 'max:'.count(TimeControl::cases())],
            'time_control.*' => ['string', Rule::enum(TimeControl::class)],
            'region' => ['nullable', 'string', 'max:50'],
            'language' => ['nullable', 'string', 'max:50'],
            'sort' => ['nullable', 'string', Rule::in(self::SORTS)],
            'page' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ];
    }

    /**
     * Filters echoed back to the frontend so the UI can hydrate its state
     * from the URL. Returns only sanitized values; bad inputs were dropped
     * by validation. `game` defaults to chess (the only v1 game).
     *
     * @return array{game: string, stake_min: ?float, stake_max: ?float, skill_min: ?int, skill_max: ?int, time_control: array<int, string>, region: ?string, language: ?string, sort: string}
     */
    public function filters(): array
    {
        return [
            'game' => $this->input('game', Game::Chess->value),
            'stake_min' => $this->filled('stake_min') ? (float) $this->input('stake_min') : null,
            'stake_max' => $this->filled('stake_max') ? (float) $this->input('stake_max') : null,
            'skill_min' => $this->filled('skill_min') ? (int) $this->input('skill_min') : null,
            'skill_max' => $this->filled('skill_max') ? (int) $this->input('skill_max') : null,
            'time_control' => array_values((array) $this->input('time_control', [])),
            'region' => $this->input('region') ?: null,
            'language' => $this->input('language') ?: null,
            'sort' => $this->input('sort', 'newest'),
        ];
    }
}
