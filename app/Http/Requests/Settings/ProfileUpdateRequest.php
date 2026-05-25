<?php

namespace App\Http\Requests\Settings;

use App\Concerns\ProfileValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ProfileUpdateRequest extends FormRequest
{
    use ProfileValidationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * Bio + avatar are profile-update-only (not part of registration), so
     * they live here rather than in the shared `ProfileValidationRules`
     * trait. Avatar accepts JPG / PNG / WebP only — same MIME whitelist as
     * the Spatie media collection on `User`, both layers enforce the set.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...$this->profileRules($this->user()->id),
            'bio' => ['nullable', 'string', 'max:500'],
            'avatar' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ];
    }

    /**
     * Custom messages for the rules above. Shared with `CreateNewUser` via
     * the `ProfileValidationRules` trait so registration and profile update
     * present the same UX for the same constraint.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->profileMessages();
    }
}
