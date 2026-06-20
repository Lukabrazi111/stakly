<?php

namespace App\Http\Requests\GameMatch;

use App\Enums\MatchStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Spatie query-builder URL contract: `/matches?filter[status]=pending&page=2`.
 * Bad / unknown query params redirect to a clean /matches rather than 422
 * — a stale share-link should land on the unfiltered index, not an error.
 */
class IndexMatchesRequest extends FormRequest
{
    public const FILTER_KEYS = ['status'];

    protected $redirect = '/matches';

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
            'filter' => ['nullable', 'array:'.implode(',', self::FILTER_KEYS)],
            'filter.status' => ['nullable', 'string', Rule::enum(MatchStatus::class)],
            'page' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ];
    }

    /**
     * @return array{status: ?string}
     */
    public function filters(): array
    {
        $filter = (array) $this->input('filter', []);

        return [
            'status' => ! empty($filter['status']) ? (string) $filter['status'] : null,
        ];
    }
}
