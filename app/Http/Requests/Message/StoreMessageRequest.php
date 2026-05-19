<?php

namespace App\Http\Requests\Message;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a chat message POST. Body: `{ content?: string, file?: UploadedFile }`.
 *
 * Either `content` or `file` (or both) must be present — the user can send
 * a text-only message, an image-only message (screenshot, no caption), or
 * an image with caption.
 *
 * Participant + match-status checks happen at the controller / Action layer.
 * This request validates payload shape + product-level caps (2000-char text
 * cap from Phase 2; 5 MB / image-only file cap from Phase 3 Slice 1).
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

    /**
     * Maximum upload size in KB. 5 MB matches the milestones.md Phase 3
     * lock — large enough for clean screenshots, small enough to keep
     * storage + bandwidth bounded.
     */
    public const MAX_FILE_SIZE_KB = 5120;

    /**
     * Image-only uploads in v1. PDFs / videos / generic files are rejected.
     * Videos belong externally + posted as links (Phase 3 Slice 2).
     */
    public const ALLOWED_MIMES = 'jpeg,jpg,png,webp';

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Trim whitespace BEFORE validation. Two reasons:
     *
     *   1. A whitespace-only message would otherwise pass `min:1` and land in
     *      the DB as a blank bubble.
     *   2. When the user sends an image with no caption, the form library may
     *      still ship an empty string for `content` — we collapse that to
     *      null so the `required_without:file` gate sees the actual absence.
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
                'image',
                'mimes:'.self::ALLOWED_MIMES,
                'max:'.self::MAX_FILE_SIZE_KB,
            ],
            // Client-generated UUID for optimistic-UI matching. Optional —
            // when present, echoed back through the broadcast so the sender's
            // frontend can replace its pending bubble with the confirmed one.
            // Validated as UUID format so a malformed string can't slip in
            // and inflate the broadcast payload.
            'correlation_id' => ['nullable', 'string', 'uuid'],
        ];
    }
}
