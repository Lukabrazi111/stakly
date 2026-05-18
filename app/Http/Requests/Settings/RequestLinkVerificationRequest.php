<?php

namespace App\Http\Requests\Settings;

use App\Enums\LinkedAccountProvider;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

/**
 * Validates `POST /settings/linked-accounts` — the first step of bio-code
 * linking. The action layer (`RequestLinkVerificationAction`) re-validates
 * format defensively so it stays correct if called outside HTTP context.
 */
class RequestLinkVerificationRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'provider' => ['required', new Enum(LinkedAccountProvider::class)],
            'username' => [
                'required',
                'string',
                'min:2',
                'max:25',
                // Provider-specific length/character validation is enforced
                // by the action; loose pre-check here keeps obvious garbage
                // off the request layer.
                'regex:/^[a-zA-Z0-9_-]{2,25}$/',
            ],
        ];
    }

    public function provider(): LinkedAccountProvider
    {
        return LinkedAccountProvider::from($this->validated('provider'));
    }

    public function username(): string
    {
        return $this->validated('username');
    }
}
