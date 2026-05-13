<?php

namespace App\Concerns;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

trait ProfileValidationRules
{
    /**
     * Get the validation rules used to validate user profiles.
     *
     * @return array<string, array<int, ValidationRule|array<mixed>|string>>
     */
    protected function profileRules(?int $userId = null): array
    {
        return [
            'name' => $this->nameRules(),
            'email' => $this->emailRules($userId),
        ];
    }

    /**
     * Custom validation messages shared by `CreateNewUser` (Fortify action,
     * uses `Validator::make`) and `ProfileUpdateRequest` (FormRequest,
     * overrides `messages()`). Keeping them in one place ensures registration
     * and profile-update show the same UX for the same constraint.
     *
     * @return array<string, string>
     */
    protected function profileMessages(): array
    {
        return [
            'name.regex' => __('Your name can only contain Latin letters (A-Z), spaces, hyphens, apostrophes, and periods.'),
        ];
    }

    /**
     * Get the validation rules used to validate user names.
     *
     * Stakly accepts pure ASCII Latin names only — same posture as Bybit and
     * other regulated crypto platforms. The regex:
     *   - `(?=.*[a-zA-Z])` — must contain at least one letter (kills "..." / "---")
     *   - `[a-zA-Z '\-\.]+`  — allowed chars: letters, spaces, apostrophes, hyphens, periods
     *
     * Explicitly rejected: accents (François), non-Latin scripts (Дмитрий,
     * 李明), numbers (John2), emojis, other symbols.
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function nameRules(): array
    {
        return [
            'required', 'string', 'max:255',
            'regex:/^(?=.*[a-zA-Z])[a-zA-Z \'\-\.]+$/',
        ];
    }

    /**
     * Get the validation rules used to validate user emails.
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function emailRules(?int $userId = null): array
    {
        return [
            'required',
            'string',
            'email',
            'max:255',
            $userId === null
                ? Rule::unique(User::class)
                : Rule::unique(User::class)->ignore($userId),
        ];
    }
}
