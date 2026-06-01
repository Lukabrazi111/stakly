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

    /**
     * Full notifications history at `/notifications`. M27 Phase 2 ships the
     * controller; Phase 4 ships the React page.
     */
    public function index(Request $request): Response
    {
        $notifications = $request->user()
            ->playerNotifications()
            ->paginate(self::INDEX_PER_PAGE);

        return Inertia::render('notifications/index', [
            'notifications' => NotificationResource::collection($notifications),
        ]);
    }

    /**
     * Last N notifications for the bell dropdown. Fetched via Inertia v3's
     * `useHttp()` on dropdown open — JSON, not an Inertia visit.
     */
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

    /**
     * Bump `notifications_last_seen_at` to clear the bell badge. Independent
     * of per-item `read_at`. Idempotent — calling repeatedly just advances
     * the timestamp.
     */
    public function markSeen(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->forceFill(['notifications_last_seen_at' => now()])->save();

        return response()->json([
            'notifications_last_seen_at' => $user->notifications_last_seen_at?->toIso8601String(),
        ]);
    }

    /**
     * Per-item read toggle. Lookup goes through `playerNotifications()` so a
     * notification belonging to another user (or a Filament admin row) 404s
     * — avoids leaking existence. Idempotent: `markAsRead` no-ops when
     * `read_at` is already set.
     */
    public function markRead(Request $request, string $notification): JsonResponse
    {
        $request->user()
            ->playerNotifications()
            ->findOrFail($notification)
            ->markAsRead();

        return response()->json(['ok' => true]);
    }

    /**
     * Mark every unread PlayerNotification as read in one query. Filament
     * admin notifications on the same table are untouched.
     */
    public function markAllRead(Request $request): JsonResponse
    {
        $request->user()
            ->playerNotifications()
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['ok' => true]);
    }
}
