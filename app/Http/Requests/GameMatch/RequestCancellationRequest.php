<?php

namespace App\Http\Requests\GameMatch;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates an M10 cancellation request body: `{ reason?: string }`.
 *
 * Reason is optional — most cancellations are AFK / misclick / "lost
 * interest" and don't need explaining. When supplied it's capped at 200
 * chars so the system message renders cleanly inside the chat narration
 * (longer free-text belongs in the chat itself, not the lifecycle
 * message).
 *
 * Authorization (participant + Pending + no-open-request + past-cooldown)
 * lives in `GameMatchPolicy::requestCancellation`; this request just
 * shapes the payload.
 *
 * Auth + email verification are enforced at the route middleware layer.
 */
class RequestCancellationRequest extends FormRequest
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
            'reason' => ['nullable', 'string', 'max:200'],
        ];
    }
}
