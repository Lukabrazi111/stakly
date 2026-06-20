<?php

namespace App\Actions\Profile;

use App\Models\User;
use App\Models\UsernameHistory;
use App\Support\BanGuard;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Atomic username change with 30-day reservation. Re-checks the same
 * blockers the FormRequest enforced (cooldown, in-flight match, reservation,
 * uniqueness) inside a row-locked transaction to close the
 * validate-then-act race. Releases the old handle into `username_history`
 * with `released_at = now + 30 days` so nobody — including the original
 * holder — can reclaim it during the window.
 *
 * No-op when `$newUsername` equals the current handle.
 */
class ChangeUsernameAction
{
    public function handle(User $user, string $newUsername): void
    {
        DB::transaction(function () use ($user, $newUsername) {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);

            if ($locked->username === $newUsername) {
                return;
            }

            $this->assertNotBlocked($locked);
            $this->assertAvailable($locked, $newUsername);
            $this->reserveOldHandle($locked);
            $this->applyNewHandle($locked, $newUsername);
            $user->setRawAttributes($locked->getAttributes());
        });
    }

    private function assertNotBlocked(User $user): void
    {
        $blockers = $user->usernameChangeBlockers();

        if ($blockers === []) {
            return;
        }

        throw ValidationException::withMessages([
            'username' => match ($blockers[0]) {
                'banned' => BanGuard::rejectionMessage(),
                'cooldown' => __('You can change your username again on :date.', [
                    'date' => $user->usernameChangeAvailableAt()?->format('M j, Y') ?? '',
                ]),
                'in_flight_match' => __("You can't change your username while you have a match in progress."),
                default => __('Username cannot be changed right now.'),
            },
        ]);
    }

    private function assertAvailable(User $user, string $newUsername): void
    {
        if (in_array($newUsername, User::RESERVED_USERNAMES, true)) {
            throw ValidationException::withMessages([
                'username' => __('That username is reserved.'),
            ]);
        }

        $takenByAnotherUser = User::query()
            ->where('username', $newUsername)
            ->where('id', '!=', $user->id)
            ->exists();

        if ($takenByAnotherUser) {
            throw ValidationException::withMessages([
                'username' => __('That username is already taken.'),
            ]);
        }

        $inReservation = UsernameHistory::reserved()
            ->where('username', $newUsername)
            ->exists();

        if ($inReservation) {
            throw ValidationException::withMessages([
                'username' => __('That username is reserved. Try another.'),
            ]);
        }
    }

    private function reserveOldHandle(User $user): void
    {
        $user->usernameHistory()->create([
            'username' => $user->username,
            'released_at' => CarbonImmutable::now()->addDays(User::USERNAME_RESERVATION_DAYS),
        ]);
    }

    private function applyNewHandle(User $user, string $newUsername): void
    {
        $user->username = $newUsername;
        $user->username_changed_at = CarbonImmutable::now();
        $user->save();
    }
}
