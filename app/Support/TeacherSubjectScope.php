<?php

namespace App\Support;

use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;

/**
 * Shared authorization check verifying a teacher is assigned to a subject.
 */
final class TeacherSubjectScope
{
    public static function findAssignedTeacher(?User $user, int|string|null $subjectId): ?Teacher
    {
        if (! $user || ! $subjectId) {
            return null;
        }

        $teacher = Teacher::where('email', $user->email)->first();
        if (! $teacher) {
            return null;
        }

        $assigned = Subject::where('id', (int) $subjectId)
            ->whereHas('teachers', fn ($q) => $q->where('teachers.id', $teacher->id))
            ->exists();

        return $assigned ? $teacher : null;
    }
}
