<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use App\Services\ScheduledContentSyncService;

class NotificationController extends BaseStaffNotificationController
{
    protected function syncNotificationsForUser(User $user): void
    {
        app(ScheduledContentSyncService::class)->syncAll();
        $this->syncService->syncForTeacher($user);
    }
}
