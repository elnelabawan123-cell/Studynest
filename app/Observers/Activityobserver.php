<?php

namespace App\Observers;

use App\Models\Activity;
use App\Models\TeacherActivityLog;

/**
 * Auto-logs teacher actions on activities (Quiz / Assignment / Activity).
 * Registered in App\Providers\AppServiceProvider.
 */
class ActivityObserver
{
    public function created(Activity $activity): void
    {
        // Map activity types to log action names
        $logType = match ($activity->type) {
            'Quiz'       => 'quiz_created',
            'Assignment' => 'assignment_created',
            'Activity'   => 'task_created',
            default      => 'activity_created',
        };

        TeacherActivityLog::create([
            'teacher_id'     => $activity->teacher_id,
            'subject_id'     => $activity->subject_id,
            'reference_id'   => $activity->id,
            'reference_type' => 'activity',
            'activity_type'  => $logType,
            'title'          => $activity->title,
            'description'    => "Created {$activity->type}",
            'status'         => $activity->status,
        ]);
    }
}