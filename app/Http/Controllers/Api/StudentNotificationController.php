<?php

namespace App\Http\Controllers\Api;

use App\Models\StudentNotification;
use App\Services\ScheduledContentSyncService;
use App\Services\StudentNotificationSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StudentNotificationController extends BaseApiController
{
    public function __construct(
        private readonly StudentNotificationSyncService $syncService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        app(ScheduledContentSyncService::class)->syncAll();

        $user = $request->user();
        $this->syncService->syncForStudent($user);

        $notifications = StudentNotification::where('student_user_id', $user->id)
            ->active()
            ->orderByDesc('event_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        $unreadCount = StudentNotification::where('student_user_id', $user->id)
            ->unread()
            ->count();

        return $this->success([
            'notifications' => $notifications->map->toApiArray()->values(),
            'unread_count'  => $unreadCount,
        ]);
    }

    public function markRead(Request $request, StudentNotification $notification): JsonResponse
    {
        $user = $request->user();
        abort_unless($notification->student_user_id === $user->id, 403, 'Unauthorized.');

        if ($notification->dismissed_at === null && $notification->read_at === null) {
            $notification->forceFill(['read_at' => now()])->save();
        }

        $unreadCount = StudentNotification::where('student_user_id', $user->id)
            ->unread()
            ->count();

        return $this->success([
            'notification' => $notification->fresh()->toApiArray(),
            'unread_count' => $unreadCount,
        ]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $user = $request->user();

        $updated = StudentNotification::where('student_user_id', $user->id)
            ->active()
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return $this->success([
            'updated'      => $updated,
            'unread_count' => 0,
        ]);
    }

    public function destroy(Request $request, string $notificationId): JsonResponse
    {
        $user = $request->user();
        $now = now();

        if (ctype_digit($notificationId)) {
            $notification = StudentNotification::findOrFail((int) $notificationId);
            abort_unless($notification->student_user_id === $user->id, 403, 'Unauthorized.');

            $notification->forceFill([
                'read_at'      => $notification->read_at ?? $now,
                'dismissed_at' => $notification->dismissed_at ?? $now,
            ])->save();
        } else {
            abort_unless(
                preg_match('/^[A-Za-z0-9:._-]{1,120}$/', $notificationId) === 1,
                422,
                'Invalid notification key.'
            );

            $existing = StudentNotification::where('student_user_id', $user->id)
                ->where('notification_key', $notificationId)
                ->first();

            if ($existing) {
                $existing->forceFill([
                    'read_at'      => $existing->read_at ?? $now,
                    'dismissed_at' => $existing->dismissed_at ?? $now,
                ])->save();
            } else {
                StudentNotification::create([
                    'student_user_id'  => $user->id,
                    'notification_key' => $notificationId,
                    'type'             => 'system',
                    'title'            => 'Dismissed notification',
                    'body'             => '',
                    'event_at'         => $now,
                    'read_at'          => $now,
                    'dismissed_at'     => $now,
                ]);
            }
        }

        $unreadCount = StudentNotification::where('student_user_id', $user->id)
            ->unread()
            ->count();

        return $this->success([
            'dismissed'    => true,
            'unread_count' => $unreadCount,
        ]);
    }

    public function clearAll(Request $request): JsonResponse
    {
        $user = $request->user();
        $keys = array_values(array_filter(
            (array) $request->input('keys', []),
            fn ($key) => is_string($key) && preg_match('/^[A-Za-z0-9:._-]{1,120}$/', $key) === 1
        ));

        $cleared = DB::transaction(function () use ($user, $keys) {
            $this->syncService->syncForStudent($user);

            $now = now();
            StudentNotification::where('student_user_id', $user->id)
                ->active()
                ->whereNull('read_at')
                ->update([
                    'read_at'    => $now,
                    'updated_at' => $now,
                ]);

            $updated = StudentNotification::where('student_user_id', $user->id)
                ->active()
                ->update([
                    'dismissed_at' => $now,
                    'updated_at'   => $now,
                ]);

            foreach ($keys as $key) {
                $existing = StudentNotification::where('student_user_id', $user->id)
                    ->where('notification_key', $key)
                    ->first();

                if ($existing) {
                    if ($existing->dismissed_at === null || $existing->read_at === null) {
                        $existing->forceFill([
                            'dismissed_at' => $existing->dismissed_at ?? $now,
                            'read_at'      => $existing->read_at ?? $now,
                        ])->save();
                    }
                } else {
                    StudentNotification::create([
                        'student_user_id'  => $user->id,
                        'notification_key' => $key,
                        'type'             => 'system',
                        'title'            => 'Dismissed notification',
                        'body'             => '',
                        'event_at'         => $now,
                        'read_at'          => $now,
                        'dismissed_at'     => $now,
                    ]);
                }
            }

            return $updated;
        });

        return $this->success([
            'cleared'      => $cleared,
            'unread_count' => 0,
        ]);
    }
}
