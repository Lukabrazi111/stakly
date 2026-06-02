<?php

namespace App\Http\Requests\Message;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Chat message POST: `{ content?: string, file?: UploadedFile }`. Either
 * `content` or `file` (or both) must be present.
 *
 * Participant + match-status checks live in the controller / Action layer.
 * Auth + email verification are enforced at the route middleware layer.
 */
class StoreMessageRequest extends FormRequest
{
    public const MAX_CONTENT_LENGTH = 2000;

    /** KB — caps bandwidth + storage while leaving room for clean screenshots. */
    public const MAX_FILE_SIZE_KB = 5120;

    /** Image formats + PDF. Chat is the evidence channel for disputes, so
     *  PDF receipts / fairplay reports / printable game records belong here. */
    public const ALLOWED_MIMES = 'jpeg,jpg,png,webp,pdf';

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Trim whitespace BEFORE validation:
     *   1. A whitespace-only message would pass `min:1` and land as a blank bubble.
     *   2. An image with no caption may ship `content=""` — collapse to null
     *      so `required_without:file` sees the actual absence.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('content')) {
            $raw = $this->input('content');
            $trimmed = is_string($raw) ? trim($raw) : '';

            $this->merge(['content' => $trimmed === '' ? null : $trimmed]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'content' => [
                'nullable',
                'required_without:file',
                'string',
                'min:1',
                'max:'.self::MAX_CONTENT_LENGTH,
            ],
            'file' => [
                'nullable',
                'required_without:content',
                'file',
                'mimes:'.self::ALLOWED_MIMES,
                'max:'.self::MAX_FILE_SIZE_KB,
            ],
            // Client-generated UUID echoed back through the broadcast so
            // the sender's FE can replace its pending bubble with the
            // confirmed one. UUID-validated to keep broadcast payloads bounded.
            'correlation_id' => ['nullable', 'string', 'uuid'],
        ];
    }
}
