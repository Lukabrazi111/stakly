<?php

namespace App\Http\Requests\GameMatch;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Cancellation request body: `{ reason?: string }`. Reason capped at 200
 * chars so the system message renders cleanly in chat narration.
 *
 * Authorization (participant + Pending + no-open-request + past-cooldown)
 * lives in `GameMatchPolicy::requestCancellation`. Auth + email verification
 * are enforced at the route middleware layer.
 */
class RequestCancellationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalize whitespace-only input to null so the model stores either
     * a meaningful reason or `null` — never `"   "`.
     */
    protected function prepareForValidation(): void
    {
        $raw = $this->input('reason');

        if (! is_string($raw)) {
            return;
        }

        $trimmed = trim($raw);

        $this->merge([
            'reason' => $trimmed === '' ? null : $trimmed,
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:200'],
        ];
    }
}
