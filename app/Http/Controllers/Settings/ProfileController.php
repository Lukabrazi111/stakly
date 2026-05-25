<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/profile', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Update the user's profile information.
     *
     * Avatar arrives as a multipart file and is extracted from the
     * validated array before `fill()` so the file instance never reaches
     * mass-assignment. After the scalar fields save, the Spatie
     * `profile-avatar` single-file collection on `User` swaps in the new
     * upload (the previous file is deleted automatically).
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $avatarFile = $request->file('avatar');
        unset($validated['avatar']);

        $user = $request->user();
        $user->fill($validated);

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        if ($avatarFile !== null) {
            $user->addMedia($avatarFile)->toMediaCollection('profile-avatar');
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile updated.')]);

        return to_route('profile.edit');
    }

    /**
     * Remove the user's avatar, reverting them to the gradient-initials
     * fallback. Idempotent — clearing an empty collection is a no-op and
     * still returns a clean toast so the UI feels consistent (the FE only
     * surfaces the button when an avatar exists, but a duplicate click /
     * stale view should never 500).
     */
    public function destroyAvatar(Request $request): RedirectResponse
    {
        $request->user()->clearMediaCollection('profile-avatar');

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Avatar removed.')]);

        return to_route('profile.edit');
    }
}
