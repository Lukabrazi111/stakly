<?php

namespace App\Http\Requests\Wallet;

use App\Enums\WalletTransactionType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the wallet history index URL. Same Spatie query-builder convention
 * as `IndexListingsRequest`:
 *
 *   /wallet/history?filter[type]=deposit&page=2
 *
 * Bad input redirects to a clean `/wallet/history` so a stale share-link or
 * a malformed manual edit never lands the user on a 422 wall.
 */
class IndexHistoryRequest extends FormRequest
{
    public const FILTER_KEYS = ['type'];

    protected $redirect = '/wallet/history';

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
            'filter.type' => ['nullable', 'string', Rule::enum(WalletTransactionType::class)],
            'page' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ];
    }

    /**
     * Flat filter shape echoed to the frontend so the UI can hydrate from URL.
     *
     * @return array{type: ?string}
     */
    public function filters(): array
    {
        $filter = (array) $this->input('filter', []);

        return [
            'type' => ! empty($filter['type']) ? (string) $filter['type'] : null,
        ];
    }
}
