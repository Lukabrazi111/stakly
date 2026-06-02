<?php

namespace App\Http\Requests\Settings;

use App\Concerns\ProfileValidationRules;
use App\Models\User;
use App\Models\UsernameHistory;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProfileUpdateRequest extends FormRequest
{
    use ProfileValidationRules;

    /**
     * `^[a-z0-9]+(?:-[a-z0-9]+)*$` — lowercase alphanumeric segments joined
     * by single hyphens. No leading / trailing / consecutive hyphens. Length
     * is enforced separately to match the column.
     */
    private const USERNAME_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('username'))) {
            $this->merge(['username' => strtolower($this->input('username'))]);
        }
    }

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
            'username' => [
                'sometimes',
                'required',
                'string',
                'min:3',
                'max:30',
                'regex:'.self::USERNAME_PATTERN,
                Rule::unique(User::class)->ignore($this->user()->id),
            ],
        ];
    }

    /**
     * Run AFTER the per-field rules pass — this is where we layer the
     * domain checks (cooldown, in-flight match, history reservation) that
     * only matter when the username is actually changing.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $user = $this->user();
                $submitted = $this->input('username');

                if (! is_string($submitted) || $submitted === $user->username) {
                    return;
                }

                if ($validator->errors()->has('username')) {
                    return;
                }

                if (in_array($submitted, User::RESERVED_USERNAMES, true)) {
                    $validator->errors()->add('username', __('That username is reserved.'));

                    return;
                }

                if (UsernameHistory::reserved()->where('username', $submitted)->exists()) {
                    $validator->errors()->add('username', __('That username is reserved. Try another.'));

                    return;
                }

                foreach ($user->usernameChangeBlockers() as $blocker) {
                    $validator->errors()->add('username', $this->blockerMessage($blocker, $user));
                }
            },
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
        return [
            ...$this->profileMessages(),
            'username.regex' => __('Use lowercase letters, numbers, and single hyphens between segments.'),
            'username.unique' => __('That username is already taken.'),
            'username.min' => __('Username must be at least 3 characters.'),
            'username.max' => __('Username must be 30 characters or fewer.'),
        ];
    }

    private function blockerMessage(string $blocker, User $user): string
    {
        return match ($blocker) {
            'cooldown' => __('You can change your username again on :date.', [
                'date' => $user->usernameChangeAvailableAt()?->format('M j, Y') ?? '',
            ]),
            'in_flight_match' => __("You can't change your username while you have a match in progress."),
            default => __('Username cannot be changed right now.'),
        };
    }
}
