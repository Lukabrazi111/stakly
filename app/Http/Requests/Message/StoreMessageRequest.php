<?php

namespace App\Http\Requests\Message;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a chat message POST. Body: `{ content: string }`.
 *
 * Participant + match-status checks happen at the controller / Action layer.
 * This request only validates the payload shape + the product-level content
 * cap (2000 chars, locked in milestones.md M8 Phase 2).
 *
 * Auth + email verification are enforced at the route middleware layer.
 */
class StoreMessageRequest extends FormRequest
{
    /**
     * Locked at 2000 in milestones.md. Covers regular chat (typical < 200
     * chars) with headroom for Phase 5 paste-PGN evidence. Above this, the
     * user should host externally and post a link.
     */
    public const MAX_CONTENT_LENGTH = 2000;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Trim whitespace BEFORE validation so a whitespace-only message fails
     * `min:1`. Without this, `"   "` would pass `required` + `min:1` and
     * land in the DB as a blank-looking message.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('content'))) {
            $this->merge(['content' => trim($this->input('content'))]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'content' => ['required', 'string', 'min:1', 'max:'.self::MAX_CONTENT_LENGTH],
        ];
    }
}
