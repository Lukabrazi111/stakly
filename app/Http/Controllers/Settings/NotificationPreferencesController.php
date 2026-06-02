<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Notifications\PlayerNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class NotificationPreferencesController extends Controller
{
    public function edit(Request $request): Response
    {
        $user = $request->user();
        $user->load('notificationPreferences');

        $preferences = collect(PlayerNotification::CONFIGURABLE_EVENT_TYPES)
            ->mapWithKeys(fn (string $eventType) => [
                $eventType => $user->getNotificationPreference($eventType),
            ])
            ->all();

        return Inertia::render('settings/notifications', [
            'preferences' => $preferences,
            'mandatoryEventTypes' => PlayerNotification::MANDATORY_EVENT_TYPES,
            'configurableEventTypes' => PlayerNotification::CONFIGURABLE_EVENT_TYPES,
            'soundChoices' => PlayerNotification::SOUND_CHOICES,
            'notificationSound' => $user->notification_sound ?? PlayerNotification::DEFAULT_SOUND_CHOICE,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'preferences' => ['required', 'array'],
            'preferences.*.in_app' => ['required', 'boolean'],
            'preferences.*.sound' => ['required', 'boolean'],
            'preferences.*.email' => ['required', 'boolean'],
            'notification_sound' => ['required', Rule::in(PlayerNotification::SOUND_CHOICES)],
        ]);

        $user = $request->user();

        foreach ($data['preferences'] as $eventType => $prefs) {
            if (! in_array($eventType, PlayerNotification::CONFIGURABLE_EVENT_TYPES, true)) {
                continue;
            }

            if (in_array($eventType, PlayerNotification::MANDATORY_EVENT_TYPES, true)) {
                $prefs['in_app'] = true;
            }

            $user->notificationPreferences()->updateOrCreate(
                ['event_type' => $eventType],
                [
                    'in_app' => $prefs['in_app'],
                    'sound' => $prefs['sound'],
                    'email' => $prefs['email'],
                ],
            );
        }

        $user->forceFill(['notification_sound' => $data['notification_sound']])->save();

        return back();
    }
}
