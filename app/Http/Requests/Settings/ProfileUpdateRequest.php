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
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return $this->profileRules($this->user()->id);
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
