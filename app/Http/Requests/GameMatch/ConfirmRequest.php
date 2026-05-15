<?php

namespace App\Http\Requests\GameMatch;

use App\Enums\MatchOutcome;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a player's outcome confirmation. Body: `{ outcome: 'won' | 'lost' }`.
 *
 * Authorization (participant + Pending status only) is enforced by the
 * controller via `GameMatchPolicy::confirm`. This request just validates
 * the payload shape.
 *
 * Auth + email verification are enforced at the route middleware layer.
 */
class ConfirmRequest extends FormRequest
{
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
            'outcome' => ['required', 'string', Rule::enum(MatchOutcome::class)],
        ];
    }
}
