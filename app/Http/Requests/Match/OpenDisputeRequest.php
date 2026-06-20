<?php

namespace App\Http\Requests\Match;

use App\Http\Requests\Message\StoreMessageRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Dispute open: `{ reason: string, evidence?: UploadedFile }`. Reason required —
 * locking an opponent's stake without articulating the claim creates an
 * unfair information gap and gives admin nothing to anchor on if the API
 * re-query lands Unknown.
 *
 * Participant + status checks live in `GameMatchPolicy::openDispute`.
 */
class OpenDisputeRequest extends FormRequest
{
    public const REASON_MAX = 1000;

    /** Image-or-PDF: chat is image-only, but dispute evidence often arrives
     *  as a PDF (game-platform receipts, fairplay reports, screenshots
     *  bundled by the user). */
    public const ALLOWED_MIMES = 'jpeg,jpg,png,webp,pdf';

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('reason')) {
            $raw = $this->input('reason');
            $trimmed = is_string($raw) ? trim($raw) : '';

            $this->merge(['reason' => $trimmed === '' ? null : $trimmed]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'reason' => [
                'nullable',
                'required_without:evidence',
                'string',
                'max:'.self::REASON_MAX,
            ],
            'evidence' => [
                'nullable',
                'required_without:reason',
                'file',
                'mimes:'.self::ALLOWED_MIMES,
                'max:'.StoreMessageRequest::MAX_FILE_SIZE_KB,
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required_without' => __('Tell us what went wrong, or attach a screenshot / PDF.'),
            'evidence.required_without' => __('Tell us what went wrong, or attach a screenshot / PDF.'),
        ];
    }
}
