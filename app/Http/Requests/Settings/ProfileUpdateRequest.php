<?php

namespace App\Http\Requests\Settings;

use App\Concerns\ProfileValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ProfileUpdateRequest extends FormRequest
{
    use ProfileValidationRules;

    /**
     * Bio + avatar are profile-update-only (not part of registration), so
     * they're here rather than in the shared `ProfileValidationRules`
     * trait. Avatar MIME whitelist is also enforced by the Spatie media
     * collection on `User`.
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
     * Shared with `CreateNewUser` via the `ProfileValidationRules` trait so
     * registration and profile update present the same UX for the same
     * constraints.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->profileMessages();
    }
}
