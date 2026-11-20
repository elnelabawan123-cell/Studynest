<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\Lesson;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Transitions due scheduled lessons ("scheduled" -> "published") and due
 * scheduled activities ("Scheduled" -> "Open") idempotently and dispatches
 * student/teacher notifications cleanly both from Artisan cron commands and
 * on-demand API reads (so deployments without a dedicated cron worker still
 * publish on time).
 */
class ScheduledContentSyncService
{
    public function __construct(
        private readonly StudentNotificationSyncService $studentNotificationSync,
        private readonly StaffNotificationSyncService $staffNotificationSync,
    ) {}

    public function syncAll(): void
    {
        $this->publishDueLessons();
        $this->openDueActivities();
    }

    /**
     * @return array<int, Lesson>
     */
    public function publishDueLessons(): array
    {
        if (! Lesson::dueForPublish()->exists()) {
            return [];
        }

        $dueIds = Lesson::dueForPublish()->pluck('id');
        $published = [];

        foreach ($dueIds as $lessonId) {
            $lesson = DB::transaction(function () use ($lessonId) {
                $candidate = Lesson::where('id', $lessonId)
                    ->where('status', 'scheduled')
                    ->where('scheduled_at', '<=', now())
                    ->first();

                if (! $candidate) {
                    return null;
                }

                $candidate->update([
                    'status'       => 'published',
                    'published_at' => now(),
                ]);

                return $candidate->fresh('subject');
            });

            if ($lesson) {
                $this->studentNotificationSync->dispatchForLesson($lesson);
                $this->staffNotificationSync->dispatchLessonPublishedForTeacher($lesson);
                $published[] = $lesson;
            }
        }

        return $published;
    }

    /**
     * @return array<int, Activity>
     */
    public function openDueActivities(): array
    {
        if (! Activity::where('status', 'Scheduled')->exists()) {
            return [];
        }

        $now = now();
        $timezone = config('app.timezone', 'Asia/Manila');
        $opened = [];

        $candidates = Activity::where('status', 'Scheduled')->get();

        foreach ($candidates as $candidate) {
            if (! $candidate->open_date) {
                continue;
            }

            $openTime = $candidate->open_time ?: '00:00';
            $openAt = Carbon::parse($candidate->open_date->format('Y-m-d') . ' ' . $openTime, $timezone)
                ->setTimezone($timezone);

            if ($openAt->gt($now)) {
                continue;
            }

            $activity = DB::transaction(function () use ($candidate) {
                $locked = Activity::where('id', $candidate->id)
                    ->where('status', 'Scheduled')
                    ->first();

                if (! $locked) {
                    return null;
                }

                $locked->update(['status' => 'Open']);

                return $locked->fresh('subject');
            });

            if ($activity) {
                $this->studentNotificationSync->dispatchForActivity($activity);
                $opened[] = $activity;
            }
        }

        return $opened;
    }
}
