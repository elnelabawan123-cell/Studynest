<?php

namespace Tests\Feature;

use App\Models\Section;
use App\Models\Student;
use App\Models\StudentNotification;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

class StudentNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    public function test_student_is_notified_for_uploaded_lesson_quiz_and_assignment_and_dismiss_clear_persist_in_database(): void
    {
        [$teacherUser, $subject, $enrolledUser, $otherUser] = $this->seedClassroom();

        // 1. Teacher uploads and publishes a lesson -> enrolled student notified, outsider is not.
        $lessonRes = $this->actingAs($teacherUser, 'sanctum')
            ->postJson('/api/teacher/lessons', [
                'title'      => 'Fractions Intro',
                'subject_id' => $subject->id,
                'status'     => 'published',
                'type'       => 'PDF',
            ])
            ->assertCreated();
        $lessonId = (int) $lessonRes->json('data.id');

        // 2. Teacher creates an Open Quiz and an Open Assignment + a Scheduled Lesson (should NOT notify yet).
        $quizRes = $this->actingAs($teacherUser, 'sanctum')
            ->postJson('/api/teacher/activities', [
                'type'           => 'Quiz',
                'title'          => 'Fraction Quiz 1',
                'subject_id'     => $subject->id,
                'open_date'      => now()->subHour()->toDateString(),
                'open_time'      => now()->subHour()->format('H:i'),
                'due_date'       => now()->addDay()->toDateString(),
                'due_time'       => '23:59',
                'total_points'   => 20,
                'time_limit'     => 15,
                'quiz_questions' => [
                    [
                        'question'       => '1/2 + 1/2 = ?',
                        'answer_type'    => 'Multiple Choice',
                        'choices'        => ['1', '2'],
                        'correct_answer' => '1',
                        'points'         => 20,
                    ],
                ],
            ])
            ->assertCreated();
        $quizId = (int) $quizRes->json('data.id');

        $assignmentRes = $this->actingAs($teacherUser, 'sanctum')
            ->postJson('/api/teacher/activities', [
                'type'         => 'Assignment',
                'title'        => 'Fraction Worksheet',
                'subject_id'   => $subject->id,
                'open_date'    => now()->subHour()->toDateString(),
                'open_time'    => now()->subHour()->format('H:i'),
                'due_date'     => now()->addDays(2)->toDateString(),
                'due_time'     => '23:59',
                'total_points' => 30,
            ])
            ->assertCreated();
        $assignmentId = (int) $assignmentRes->json('data.id');

        // 2b. A Draft Assignment and Future-Scheduled Quiz must NOT notify yet.
        $this->actingAs($teacherUser, 'sanctum')
            ->postJson('/api/teacher/activities', [
                'type'         => 'Assignment',
                'title'        => 'Unpublished Draft Assignment',
                'subject_id'   => $subject->id,
                'status'       => 'Draft',
                'open_date'    => now()->subHour()->toDateString(),
                'open_time'    => now()->subHour()->format('H:i'),
                'due_date'     => now()->addDays(2)->toDateString(),
                'due_time'     => '23:59',
                'total_points' => 10,
            ])
            ->assertCreated();

        // Enrolled student sees all 3 active notifications; outsider sees 0.
        $indexRes = $this->actingAs($enrolledUser, 'sanctum')
            ->getJson('/api/student/notifications')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 3)
            ->assertJsonCount(3, 'data.notifications');

        $notifications = collect($indexRes->json('data.notifications'));
        $lessonNotif = $notifications->firstWhere('notification_key', "lesson-{$lessonId}-published");
        $quizNotif = $notifications->firstWhere('notification_key', "activity-{$quizId}-new");
        $assignmentNotif = $notifications->firstWhere('notification_key', "activity-{$assignmentId}-new");

        $this->assertNotNull($lessonNotif);
        $this->assertSame('lesson', $lessonNotif['type']);
        $this->assertSame('lesson', $lessonNotif['target_type']);
        $this->assertSame($lessonId, $lessonNotif['target_id']);

        $this->assertNotNull($quizNotif);
        $this->assertSame('quiz', $quizNotif['type']);
        $this->assertSame('activity', $quizNotif['target_type']);
        $this->assertSame($quizId, $quizNotif['target_id']);

        $this->assertNotNull($assignmentNotif);
        $this->assertSame('assignment', $assignmentNotif['type']);

        $this->actingAs($otherUser, 'sanctum')
            ->getJson('/api/student/notifications')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 0)
            ->assertJsonCount(0, 'data.notifications');

        // 3. Student marks quiz notification read -> persists on reload.
        $this->actingAs($enrolledUser, 'sanctum')
            ->postJson("/api/student/notifications/{$quizNotif['id']}/read")
            ->assertOk()
            ->assertJsonPath('data.unread_count', 2)
            ->assertJsonPath('data.notification.is_read', true);

        // Unauthorized cross-student read/dismiss must be forbidden.
        $this->actingAs($otherUser, 'sanctum')
            ->postJson("/api/student/notifications/{$quizNotif['id']}/read")
            ->assertForbidden();
        $this->actingAs($otherUser, 'sanctum')
            ->deleteJson("/api/student/notifications/{$lessonNotif['id']}")
            ->assertForbidden();

        // 4. Student dismisses individual lesson notification -> disappears immediately & stays gone on refresh.
        $this->actingAs($enrolledUser, 'sanctum')
            ->deleteJson("/api/student/notifications/{$lessonNotif['id']}")
            ->assertOk()
            ->assertJsonPath('data.dismissed', true)
            ->assertJsonPath('data.unread_count', 1);

        $afterDismiss = $this->actingAs($enrolledUser, 'sanctum')
            ->getJson('/api/student/notifications')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 1)
            ->assertJsonCount(2, 'data.notifications');

        $remainingKeys = collect($afterDismiss->json('data.notifications'))
            ->pluck('notification_key')
            ->all();
        $this->assertNotContains("lesson-{$lessonId}-published", $remainingKeys);

        // Verify dismissed_at is stored in DB so refreshing/re-logging never recreates it.
        $dismissedRow = StudentNotification::where('student_user_id', $enrolledUser->id)
            ->where('notification_key', "lesson-{$lessonId}-published")
            ->firstOrFail();
        $this->assertNotNull($dismissedRow->dismissed_at);

        // 5. Clear All removes all remaining notifications and persists after re-sync & fresh auth session.
        $this->actingAs($enrolledUser, 'sanctum')
            ->postJson('/api/student/notifications/clear-all')
            ->assertOk()
            ->assertJsonPath('data.cleared', 2)
            ->assertJsonPath('data.unread_count', 0);

        $freshStudentUser = User::findOrFail($enrolledUser->id);
        $this->actingAs($freshStudentUser, 'sanctum')
            ->getJson('/api/student/notifications')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 0)
            ->assertJsonCount(0, 'data.notifications');

        // 6. A brand-new lesson published AFTER Clear All still generates a fresh notification!
        $secondLessonRes = $this->actingAs($teacherUser, 'sanctum')
            ->postJson('/api/teacher/lessons', [
                'title'      => 'Decimals Basics',
                'subject_id' => $subject->id,
                'status'     => 'published',
                'type'       => 'PDF',
            ])
            ->assertCreated();
        $secondLessonId = (int) $secondLessonRes->json('data.id');

        $this->actingAs($freshStudentUser, 'sanctum')
            ->getJson('/api/student/notifications')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 1)
            ->assertJsonCount(1, 'data.notifications')
            ->assertJsonPath('data.notifications.0.notification_key', "lesson-{$secondLessonId}-published");
    }

    public function test_scheduled_activity_notifies_students_once_opened(): void
    {
        [$teacherUser, $subject, $enrolledUser] = $this->seedClassroom();

        // Starts Scheduled in the future -> no notification yet.
        $activity = \App\Models\Activity::create([
            'teacher_id'   => $teacherUser->id,
            'subject_id'   => $subject->id,
            'type'         => 'Quiz',
            'title'        => 'Scheduled Quiz 1',
            'status'       => 'Scheduled',
            'open_date'    => now()->addDay()->toDateString(),
            'open_time'    => '08:00',
            'due_date'     => now()->addDays(2)->toDateString(),
            'due_time'     => '23:59',
            'total_points' => 20,
            'time_limit'   => 10,
        ]);

        $this->actingAs($enrolledUser, 'sanctum')
            ->getJson('/api/student/notifications')
            ->assertOk()
            ->assertJsonCount(0, 'data.notifications');

        // Open time arrives and scheduler opens the quiz -> notification appears.
        $activity->update([
            'open_date' => now()->subMinute()->toDateString(),
            'open_time' => now()->subMinute()->format('H:i'),
        ]);
        $this->artisan('activities:sync-status')->assertSuccessful();

        $this->actingAs($enrolledUser, 'sanctum')
            ->getJson('/api/student/notifications')
            ->assertOk()
            ->assertJsonCount(1, 'data.notifications')
            ->assertJsonPath('data.notifications.0.notification_key', "activity-{$activity->id}-new");

        // Deleting the activity cleans up its active/persisted notification row.
        $this->actingAs($teacherUser, 'sanctum')
            ->deleteJson("/api/teacher/activities/{$activity->id}")
            ->assertOk();

        $this->actingAs($enrolledUser, 'sanctum')
            ->getJson('/api/student/notifications')
            ->assertOk()
            ->assertJsonCount(0, 'data.notifications');
    }

    private function seedClassroom(): array
    {
        $teacherUser = User::create([
            'name'     => 'Teacher One',
            'email'    => 'teacher1@example.test',
            'username' => 'teacher1',
            'password' => 'password123',
            'role'     => 'teacher',
        ]);
        $teacher = Teacher::create([
            'name'          => 'Teacher One',
            'email'         => 'teacher1@example.test',
            'subject'       => 'Mathematics',
            'grade_level'   => 'Grade 4',
            'classes_count' => 1,
            'status'        => 'Active',
        ]);

        $section = Section::create([
            'name'        => 'Section A',
            'grade_level' => 'Grade 4',
        ]);

        $subject = Subject::create([
            'code'        => 'MATH4',
            'name'        => 'Mathematics',
            'grade_level' => 'Grade 4',
            'units'       => 3,
            'status'      => 'Active',
        ]);
        $subject->teachers()->attach($teacher->id);

        $enrolledUser = User::create([
            'name'     => 'Enrolled Student',
            'email'    => 'enrolled@example.test',
            'username' => 'enrolled.student',
            'password' => 'password123',
            'role'     => 'student',
        ]);
        $enrolledStudent = Student::create([
            'user_id'        => $enrolledUser->id,
            'student_number' => 'STU-2026-9001',
            'first_name'     => 'Enrolled',
            'last_name'      => 'Student',
            'email'          => 'enrolled@example.test',
            'grade'          => 'Grade 4',
            'section_id'     => $section->id,
            'status'         => 'Active',
        ]);
        $subject->students()->attach($enrolledStudent->id);

        $otherUser = User::create([
            'name'     => 'Outsider Student',
            'email'    => 'outsider@example.test',
            'username' => 'outsider.student',
            'password' => 'password123',
            'role'     => 'student',
        ]);
        Student::create([
            'user_id'        => $otherUser->id,
            'student_number' => 'STU-2026-9002',
            'first_name'     => 'Outsider',
            'last_name'      => 'Student',
            'email'          => 'outsider@example.test',
            'grade'          => 'Grade 4',
            'section_id'     => $section->id,
            'status'         => 'Active',
        ]);

        return [$teacherUser, $subject, $enrolledUser, $otherUser];
    }
}
