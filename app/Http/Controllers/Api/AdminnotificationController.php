<?php

namespace App\Http\Controllers\Api;

use App\Models\User;

class AdminnotificationController extends BaseStaffNotificationController
{
    protected function syncNotificationsForUser(User $user): void
    {
        $this->syncService->syncForAdmin($user);
    }
}
