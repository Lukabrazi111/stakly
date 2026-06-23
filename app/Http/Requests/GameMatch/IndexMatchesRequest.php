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

    /**
     * View modes for the matches index. `in_progress` (default) shows only the
     * active group ({@see MatchStatus::inProgress()}); `all` shows every status
     * and honours the `filter[status]` chips. Mirrors Bybit's
     * "Orders → In Progress / All" split (M36).
     */
    public const VIEWS = ['in_progress', 'all'];

    public const DEFAULT_VIEW = 'in_progress';

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
            'view' => ['nullable', 'string', Rule::in(self::VIEWS)],
            'filter' => ['nullable', 'array:'.implode(',', self::FILTER_KEYS)],
            'filter.status' => ['nullable', 'string', Rule::enum(MatchStatus::class)],
            'page' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ];
    }

    public function view(): string
    {
        $view = (string) $this->input('view', self::DEFAULT_VIEW);

        return in_array($view, self::VIEWS, true) ? $view : self::DEFAULT_VIEW;
    }

    /**
     * @return array{view: string, status: ?string}
     */
    public function filters(): array
    {
        $filter = (array) $this->input('filter', []);
        $view = $this->view();

        return [
            'view' => $view,
            // Status chips only apply in the All view; In Progress is a fixed group.
            'status' => $view === 'all' && ! empty($filter['status']) ? (string) $filter['status'] : null,
        ];
    }
}
