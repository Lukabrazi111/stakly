<?php

namespace App\Concerns;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

trait ProfileValidationRules
{
    /**
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
     * Shared between `CreateNewUser` (Fortify, uses `Validator::make`) and
     * `ProfileUpdateRequest` (FormRequest, overrides `messages()`) so
     * registration and profile-update show identical copy.
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
     * Stakly accepts pure ASCII Latin names only — same posture as Bybit and
     * other regulated crypto platforms. Rejects accents, non-Latin scripts,
     * numbers, emojis. The `(?=.*[a-zA-Z])` lookahead kills "..." / "---".
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
