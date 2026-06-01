<?php

namespace App\Http\Controllers;

use App\Http\Resources\NotificationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NotificationController extends Controller
{
    private const int DROPDOWN_LIMIT = 15;

    private const int INDEX_PER_PAGE = 20;

    public function index(Request $request): Response
    {
        $notifications = $request->user()
            ->playerNotifications()
            ->paginate(self::INDEX_PER_PAGE);

        return Inertia::render('notifications/index', [
            'notifications' => NotificationResource::collection($notifications),
        ]);
    }

    public function recent(Request $request): JsonResponse
    {
        $notifications = $request->user()
            ->playerNotifications()
            ->limit(self::DROPDOWN_LIMIT)
            ->get();

        return response()->json([
            'data' => NotificationResource::collection($notifications)->resolve(),
        ]);
    }

    public function markSeen(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->forceFill(['notifications_last_seen_at' => now()])->save();

        return response()->json([
            'notifications_last_seen_at' => $user->notifications_last_seen_at?->toIso8601String(),
        ]);
    }

    // 404 (not 403) for another user's notification — avoids leaking existence.
    public function markRead(Request $request, string $notification): JsonResponse
    {
        $request->user()
            ->playerNotifications()
            ->findOrFail($notification)
            ->markAsRead();

        return response()->json(['ok' => true]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $request->user()
            ->playerNotifications()
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['ok' => true]);
    }
}
