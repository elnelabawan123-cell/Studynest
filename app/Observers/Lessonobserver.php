<?php

namespace App\Observers;

use App\Models\Lesson;
use App\Models\TeacherActivityLog;

/**
 * Auto-logs teacher activity for lessons.
 * Registered in App\Providers\AppServiceProvider.
 *
 * - On create: logs 'lesson_scheduled' (or 'lesson_published' if created
 *   already published).
 * - On status flip to 'published' — whether triggered by a teacher action
 *   or by the PublishScheduledLessons scheduled command — logs 'lesson_published'.
 */
class LessonObserver
{
    public function created(Lesson $lesson): void
    {
        TeacherActivityLog::create([
            'teacher_id'     => $lesson->teacher_id,
            'subject_id'     => $lesson->subject_id,
            'reference_id'   => $lesson->id,
            'reference_type' => 'lesson',
            'activity_type'  => $lesson->status === 'published' ? 'lesson_published' : 'lesson_scheduled',
            'title'          => $lesson->title,
            'description'    => $lesson->status === 'published'
                ? 'Uploaded and published new lesson'
                : 'Scheduled new lesson for publishing',
            'status'         => ucfirst($lesson->status),
        ]);
    }

    public function updated(Lesson $lesson): void
    {
        // Emit a publish log whenever status actually transitions to 'published',
        // regardless of whether a teacher triggered it or the scheduler did.
        if (
            $lesson->wasChanged('status')
            && $lesson->status === 'published'
            && $lesson->getOriginal('status') !== 'published'
        ) {
            TeacherActivityLog::create([
                'teacher_id'     => $lesson->teacher_id,
                'subject_id'     => $lesson->subject_id,
                'reference_id'   => $lesson->id,
                'reference_type' => 'lesson',
                'activity_type'  => 'lesson_published',
                'title'          => $lesson->title,
                'description'    => 'Published lesson to students',
                'status'         => 'Published',
            ]);
            return;
        }

        // Optional: surface reschedules on already-scheduled lessons.
        if (
            $lesson->status === 'scheduled'
            && $lesson->wasChanged('scheduled_at')
        ) {
            TeacherActivityLog::create([
                'teacher_id'     => $lesson->teacher_id,
                'subject_id'     => $lesson->subject_id,
                'reference_id'   => $lesson->id,
                'reference_type' => 'lesson',
                'activity_type'  => 'lesson_rescheduled',
                'title'          => $lesson->title,
                'description'    => 'Changed the scheduled publish date/time',
                'status'         => 'Scheduled',
            ]);
        }
    }
}