<?php

namespace App\Http\Controllers\Api;

use App\Models\StaffNotification;
use App\Models\User;
use App\Services\StaffNotificationSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Shared DB-backed notification actions for teacher and admin bell dropdowns.
 */
abstract class BaseStaffNotificationController extends BaseApiController
{
    public function __construct(
        protected readonly StaffNotificationSyncService $syncService,
    ) {}

    abstract protected function syncNotificationsForUser(User $user): void;

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->syncNotificationsForUser($user);

        $items = StaffNotification::where('user_id', $user->id)
            ->active()
            ->orderByRaw('CASE WHEN read_at IS NULL THEN 0 ELSE 1 END')
            ->orderByDesc('event_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map
            ->toApiArray()
            ->values();

        return $this->success($items);
    }

    public function markRead(Request $request, StaffNotification $notification): JsonResponse
    {
        $user = $request->user();
        abort_unless((int) $notification->user_id === (int) $user->id, 403, 'Unauthorized.');

        if ($notification->dismissed_at === null && $notification->read_at === null) {
            $notification->forceFill(['read_at' => now()])->save();
        }

        $unreadCount = StaffNotification::where('user_id', $user->id)->unread()->count();

        return $this->success([
            'notification' => $notification->fresh()->toApiArray(),
            'unread_count' => $unreadCount,
        ]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $user = $request->user();
        $now = now();

        $updated = StaffNotification::where('user_id', $user->id)
            ->active()
            ->whereNull('read_at')
            ->update(['read_at' => $now, 'updated_at' => $now]);

        return $this->success([
            'updated'      => $updated,
            'unread_count' => 0,
        ], 'All notifications marked as read.');
    }

    public function destroy(Request $request, StaffNotification $notification): JsonResponse
    {
        $user = $request->user();
        abort_unless((int) $notification->user_id === (int) $user->id, 403, 'Unauthorized.');

        $now = now();
        $notification->forceFill([
            'read_at'      => $notification->read_at ?? $now,
            'dismissed_at' => $notification->dismissed_at ?? $now,
        ])->save();

        $unreadCount = StaffNotification::where('user_id', $user->id)->unread()->count();

        return $this->success([
            'dismissed'    => true,
            'unread_count' => $unreadCount,
        ], 'Notification dismissed.');
    }

    public function clearAll(Request $request): JsonResponse
    {
        $user = $request->user();

        $cleared = DB::transaction(function () use ($user) {
            $this->syncNotificationsForUser($user);

            $now = now();
            StaffNotification::where('user_id', $user->id)
                ->active()
                ->whereNull('read_at')
                ->update(['read_at' => $now, 'updated_at' => $now]);

            return StaffNotification::where('user_id', $user->id)
                ->active()
                ->update(['dismissed_at' => $now, 'updated_at' => $now]);
        });

        return $this->success([
            'cleared'      => $cleared,
            'unread_count' => 0,
        ], 'All notifications cleared.');
    }
}
