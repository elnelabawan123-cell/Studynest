<?php

namespace Tests\Feature;

use App\Models\Lesson;
use App\Models\Section;
use App\Models\StaffNotification;
use App\Models\Student;
use App\Models\StudentNotification;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

class LessonScheduleTimezoneTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    public function test_scheduled_at_preserves_app_timezone_and_converts_utc_iso_without_eight_hour_shift(): void
    {
        [$teacherUser, $subject] = $this->seedClassroom();

        // 1. Offset-aware Manila payload (+08:00) preserves exact local wall time
        $futureDate = now()->addDays(2)->format('Y-m-d');
        $createRes = $this->actingAs($teacherUser, 'sanctum')
            ->postJson('/api/teacher/lessons', [
                'title'        => 'Earth Layers',
                'subject_id'   => $subject->id,
                'status'       => 'scheduled',
                'scheduled_at' => "{$futureDate}T10:28:00+08:00",
                'type'         => 'PDF',
            ])
            ->assertCreated();

        $lessonId = (int) $createRes->json('data.id');
        $lesson = Lesson::findOrFail($lessonId);
        $this->assertSame("{$futureDate} 10:28:00", $lesson->scheduled_at->format('Y-m-d H:i:s'));
        $this->assertSame("{$futureDate}T10:28:00+08:00", $createRes->json('data.scheduled_at'));

        // 2. Legacy UTC "Z" payload (e.g. 02:28Z == 10:28+08:00) converts to 10:28 Asia/Manila instead of storing 02:28 AM!
        $nextFutureDate = now()->addDays(3)->format('Y-m-d');
        $updateRes = $this->actingAs($teacherUser, 'sanctum')
            ->putJson("/api/teacher/lessons/{$lessonId}", [
                'title'        => 'Earth Layers',
                'subject_id'   => $subject->id,
                'status'       => 'scheduled',
                'scheduled_at' => "{$nextFutureDate}T02:28:00.000Z",
            ])
            ->assertOk();

        $lesson->refresh();
        $this->assertSame("{$nextFutureDate} 10:28:00", $lesson->scheduled_at->format('Y-m-d H:i:s'));
        $this->assertSame("{$nextFutureDate}T10:28:00+08:00", $updateRes->json('data.scheduled_at'));
    }

    public function test_due_scheduled_lesson_auto_publishes_on_api_read_and_notifies_teacher_and_student(): void
    {
        [$teacherUser, $subject, $studentUser] = $this->seedClassroom();

        $lesson = Lesson::create([
            'teacher_id'   => $teacherUser->id,
            'subject_id'   => $subject->id,
            'title'        => 'Jules Earth',
            'type'         => 'PDF',
            'status'       => 'scheduled',
            'scheduled_at' => now()->subMinutes(5),
        ]);

        // Calling /api/teacher/lessons (as UI does) auto-publishes due scheduled lessons even without a cron worker
        $indexRes = $this->actingAs($teacherUser, 'sanctum')
            ->getJson('/api/teacher/lessons')
            ->assertOk();

        $row = collect($indexRes->json('data'))->firstWhere('id', $lesson->id);
        $this->assertNotNull($row);
        $this->assertSame('published', $row['status']);
        $this->assertNotNull($row['published_at']);

        // Enrolled student sees both the published lesson and its notification
        $studentLessons = $this->actingAs($studentUser, 'sanctum')
            ->getJson('/api/student/lessons')
            ->assertOk();
        $this->assertTrue(collect($studentLessons->json('data'))->contains('id', $lesson->id));

        $this->assertTrue(
            StudentNotification::where('student_user_id', $studentUser->id)
                ->where('notification_key', "lesson-{$lesson->id}-published")
                ->exists()
        );
        $this->assertTrue(
            StaffNotification::where('user_id', $teacherUser->id)
                ->where('notification_key', "lesson_auto_published:{$lesson->id}")
                ->exists()
        );
    }

    private function seedClassroom(): array
    {
        $teacherUser = User::create([
            'name'     => 'Benjo Leo Solis',
            'email'    => 'benjo@example.test',
            'username' => 'solis.SDA',
            'password' => 'password123',
            'role'     => 'teacher',
        ]);
        $teacher = Teacher::create([
            'name'          => 'Benjo Leo Solis',
            'email'         => 'benjo@example.test',
            'subject'       => 'Science',
            'grade_level'   => 'Grade 2',
            'classes_count' => 1,
            'status'        => 'Active',
        ]);

        $section = Section::create([
            'name'        => 'Grade 2 - A',
            'grade_level' => 'Grade 2',
        ]);

        $subject = Subject::create([
            'code'        => 'SCI2',
            'name'        => 'Science',
            'grade_level' => 'Grade 2',
            'units'       => 3,
            'status'      => 'Active',
        ]);
        $subject->teachers()->attach($teacher->id);

        $studentUser = User::create([
            'name'     => 'Student One',
            'email'    => 'student@example.test',
            'username' => 'student1.SDA',
            'password' => 'password123',
            'role'     => 'student',
        ]);
        $student = Student::create([
            'user_id'        => $studentUser->id,
            'student_number' => 'STU-2026-0001',
            'first_name'     => 'Student',
            'last_name'      => 'One',
            'email'          => 'student@example.test',
            'grade'          => 'Grade 2',
            'section_id'     => $section->id,
            'status'         => 'Active',
        ]);
        $subject->students()->attach($student->id);

        return [$teacherUser, $subject, $studentUser];
    }
}
