<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Profile\ChangeUsernameAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Support\BanGuard;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/profile', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Avatar is extracted from the validated array before `fill()` so the
     * file instance never reaches mass-assignment. Username changes detour
     * through `ChangeUsernameAction` which handles the row-locked re-check,
     * reservation write, and cooldown bump atomically.
     */
    public function update(ProfileUpdateRequest $request, ChangeUsernameAction $changeUsername): RedirectResponse
    {
        if (BanGuard::isBanned($request->user())) {
            Inertia::flash('toast', ['type' => 'error', 'message' => BanGuard::rejectionMessage()]);

            return to_route('profile.edit');
        }

        $validated = $request->validated();
        $avatarFile = $request->file('avatar');
        unset($validated['avatar']);

        $user = $request->user();

        $submittedUsername = $validated['username'] ?? null;
        unset($validated['username']);

        $user->fill($validated);

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        if (is_string($submittedUsername) && $submittedUsername !== $user->username) {
            $changeUsername->handle($user, $submittedUsername);
        }

        if ($avatarFile !== null) {
            $user->addMedia($avatarFile)->toMediaCollection('profile-avatar');
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile updated.')]);

        return to_route('profile.edit');
    }

    /**
     * Idempotent — clearing an empty collection is a no-op so a duplicate
     * click / stale view never 500s.
     */
    public function destroyAvatar(Request $request): RedirectResponse
    {
        $request->user()->clearMediaCollection('profile-avatar');

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Avatar removed.')]);

        return to_route('profile.edit');
    }
}
