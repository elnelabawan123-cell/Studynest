<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Lesson;
use App\Models\QuizQuestion;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    public function test_api_responses_include_security_headers(): void
    {
        $response = $this->postJson('/api/login', [
            'identifier' => 'nobody',
            'password' => 'wrong',
        ]);

        $response->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }

    public function test_student_submission_enforces_enrollment_question_scope_mime_type_and_signed_photo_urls(): void
    {
        Mail::fake();
        Storage::fake('public');

        $teacherUser = User::create([
            'name' => 'Teacher One',
            'email' => 'teacher1@example.test',
            'username' => 'teacher.one',
            'password' => 'secret123',
            'role' => 'teacher',
        ]);
        $subjectA = Subject::create(['code' => 'SUBA', 'name' => 'Subject A', 'grade_level' => 'Grade 4', 'status' => 'Active']);
        $subjectB = Subject::create(['code' => 'SUBB', 'name' => 'Subject B', 'grade_level' => 'Grade 4', 'status' => 'Active']);

        $activityA = Activity::create([
            'teacher_id' => $teacherUser->id,
            'subject_id' => $subjectA->id,
            'type' => 'Quiz',
            'title' => 'Quiz A',
            'status' => 'Open',
            'open_date' => now()->subDay()->toDateString(),
            'open_time' => '00:00',
            'due_date' => now()->addDay()->toDateString(),
            'due_time' => '23:59',
            'total_points' => 10,
            'time_limit' => 30,
        ]);
        $questionA = QuizQuestion::create([
            'activity_id' => $activityA->id,
            'order' => 1,
            'question' => 'Upload photo',
            'answer_type' => 'Photo',
            'points' => 10,
        ]);

        $activityB = Activity::create([
            'teacher_id' => $teacherUser->id,
            'subject_id' => $subjectB->id,
            'type' => 'Quiz',
            'title' => 'Quiz B',
            'status' => 'Open',
            'open_date' => now()->subDay()->toDateString(),
            'open_time' => '00:00',
            'due_date' => now()->addDay()->toDateString(),
            'due_time' => '23:59',
            'total_points' => 10,
            'time_limit' => 30,
        ]);
        $questionB = QuizQuestion::create([
            'activity_id' => $activityB->id,
            'order' => 1,
            'question' => 'Foreign question',
            'answer_type' => 'Multiple Choice',
            'choices' => ['A', 'B'],
            'correct_answer' => 'A',
            'points' => 10,
        ]);

        $studentUser = User::create([
            'name' => 'Student One',
            'email' => 'student1@example.test',
            'username' => 'student.one',
            'password' => 'secret123',
            'role' => 'student',
        ]);
        $student = Student::create([
            'user_id' => $studentUser->id,
            'student_number' => 'STU-2026-9001',
            'first_name' => 'Student',
            'last_name' => 'One',
            'email' => $studentUser->email,
            'grade' => 'Grade 4',
            'gpa' => 0,
            'status' => 'Active',
        ]);

        // Unenrolled student is blocked across all submission endpoints.
        $this->actingAs($studentUser, 'sanctum');
        $this->getJson("/api/student/activities/{$activityA->id}/submission")->assertForbidden();
        $this->postJson("/api/student/activities/{$activityA->id}/start")->assertForbidden();
        $this->postJson("/api/student/activities/{$activityA->id}/answer", [
            'quiz_question_id' => $questionA->id,
            'question_index' => 0,
        ])->assertForbidden();
        $this->postJson("/api/student/activities/{$activityA->id}/submit", [
            'answers' => [['quiz_question_id' => $questionA->id]],
        ])->assertForbidden();

        // Enroll student in Subject A only.
        $subjectA->students()->attach($student->id);
        $this->postJson("/api/student/activities/{$activityA->id}/start")->assertOk();

        // Reject question ID belonging to another activity.
        $this->postJson("/api/student/activities/{$activityA->id}/answer", [
            'quiz_question_id' => $questionB->id,
            'question_index' => 0,
            'selected_choice' => 'A',
        ])->assertUnprocessable();

        // Reject SVG uploads to prevent stored XSS via active content.
        $svgFile = UploadedFile::fake()->create('exploit.svg', 4, 'image/svg+xml');
        $this->post("/api/student/activities/{$activityA->id}/answer", [
            'quiz_question_id' => $questionA->id,
            'question_index' => 0,
            'photo' => $svgFile,
        ], ['Accept' => 'application/json'])->assertUnprocessable();

        // Accept real PNG image and return temporary signed URL instead of raw /storage/ path.
        $pngBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        $pngFile = UploadedFile::fake()->createWithContent('answer.png', $pngBytes);
        $saved = $this->post("/api/student/activities/{$activityA->id}/answer", [
            'quiz_question_id' => $questionA->id,
            'question_index' => 0,
            'photo' => $pngFile,
        ], ['Accept' => 'application/json'])->assertOk();

        $photoUrl = $saved->json('data.answers.0.photoUrl');
        $this->assertNotEmpty($photoUrl);
        $this->assertStringNotContainsString('/storage/', $photoUrl);
        $this->assertStringContainsString('signature=', $photoUrl);

        $pathOnly = parse_url($photoUrl, PHP_URL_PATH) . '?' . parse_url($photoUrl, PHP_URL_QUERY);
        $this->get($pathOnly)->assertOk();
        $this->get(parse_url($photoUrl, PHP_URL_PATH))->assertForbidden();
    }

    public function test_teacher_enrollment_and_content_creation_enforce_subject_assignment(): void
    {
        $teacherAUser = User::create([
            'name' => 'Teacher A',
            'email' => 'teachera@example.test',
            'username' => 'teacher.a',
            'password' => 'secret123',
            'role' => 'teacher',
        ]);
        $teacherA = Teacher::create([
            'name' => 'Teacher A',
            'email' => 'teachera@example.test',
            'subject' => 'Math',
            'grade_level' => 'Grade 4',
            'status' => 'Active',
        ]);
        $teacherBUser = User::create([
            'name' => 'Teacher B',
            'email' => 'teacherb@example.test',
            'username' => 'teacher.b',
            'password' => 'secret123',
            'role' => 'teacher',
        ]);
        Teacher::create([
            'name' => 'Teacher B',
            'email' => 'teacherb@example.test',
            'subject' => 'Science',
            'grade_level' => 'Grade 4',
            'status' => 'Active',
        ]);

        $subjectA = Subject::create(['code' => 'M4', 'name' => 'Math 4', 'grade_level' => 'Grade 4', 'status' => 'Active']);
        $subjectA->teachers()->attach($teacherA->id);

        $studentUser = User::create([
            'name' => 'Kid One',
            'email' => 'kid@example.test',
            'username' => 'kid.one',
            'password' => 'secret123',
            'role' => 'student',
        ]);
        $student = Student::create([
            'user_id' => $studentUser->id,
            'student_number' => 'STU-2026-9002',
            'first_name' => 'Kid',
            'last_name' => 'One',
            'email' => $studentUser->email,
            'grade' => 'Grade 4',
            'gpa' => 0,
            'status' => 'Active',
        ]);

        // Unassigned Teacher B cannot view or modify Subject A rosters or content.
        $this->actingAs($teacherBUser, 'sanctum');
        $this->getJson("/api/teacher/subjects/{$subjectA->id}/students")->assertForbidden();
        $this->getJson("/api/teacher/subjects/{$subjectA->id}/available-students")->assertForbidden();
        $this->postJson("/api/teacher/subjects/{$subjectA->id}/enroll-students", [
            'student_ids' => [$student->id],
        ])->assertForbidden();
        $this->deleteJson("/api/teacher/subjects/{$subjectA->id}/unenroll-student/{$student->id}")->assertForbidden();
        $this->postJson('/api/teacher/lessons', [
            'title' => 'Unauthorized Lesson',
            'subject_id' => $subjectA->id,
            'status' => 'published',
        ])->assertForbidden();
        $this->postJson('/api/teacher/activities', [
            'type' => 'Assignment',
            'title' => 'Unauthorized Assignment',
            'subject_id' => $subjectA->id,
            'open_date' => now()->toDateString(),
            'open_time' => '08:00',
            'due_date' => now()->addDay()->toDateString(),
            'due_time' => '17:00',
            'total_points' => 100,
        ])->assertForbidden();

        // Assigned Teacher A can manage roster, but malicious attachment_path is rejected.
        $this->actingAs($teacherAUser, 'sanctum');
        $this->postJson("/api/teacher/subjects/{$subjectA->id}/enroll-students", [
            'student_ids' => [$student->id],
        ])->assertOk();

        $this->postJson('/api/teacher/activities', [
            'type' => 'Assignment',
            'title' => 'Safe Check',
            'subject_id' => $subjectA->id,
            'open_date' => now()->toDateString(),
            'open_time' => '08:00',
            'due_date' => now()->addDay()->toDateString(),
            'due_time' => '17:00',
            'total_points' => 100,
            'attachment_path' => '../../.env',
        ])->assertUnprocessable();
    }

    public function test_student_lesson_blocks_unpublished_content_and_sanitizes_disposition_header(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('lessons/test.pdf', '%PDF-1.4 test');

        $teacherUser = User::create([
            'name' => 'Teacher L',
            'email' => 'teacherl@example.test',
            'username' => 'teacher.l',
            'password' => 'secret123',
            'role' => 'teacher',
        ]);
        $subject = Subject::create(['code' => 'ENG4', 'name' => 'English 4', 'grade_level' => 'Grade 4', 'status' => 'Active']);
        $studentUser = User::create([
            'name' => 'Student L',
            'email' => 'studentl@example.test',
            'username' => 'student.l',
            'password' => 'secret123',
            'role' => 'student',
        ]);
        $student = Student::create([
            'user_id' => $studentUser->id,
            'student_number' => 'STU-2026-9003',
            'first_name' => 'Student',
            'last_name' => 'L',
            'email' => $studentUser->email,
            'grade' => 'Grade 4',
            'gpa' => 0,
            'status' => 'Active',
        ]);
        $subject->students()->attach($student->id);

        $scheduledLesson = Lesson::create([
            'teacher_id' => $teacherUser->id,
            'subject_id' => $subject->id,
            'title' => 'Future Lesson',
            'status' => 'draft',
            'scheduled_at' => now()->addDay(),
            'file_path' => 'lessons/test.pdf',
            'file_name' => '../evil"quote.pdf',
            'mime_type' => 'application/pdf',
        ]);

        $this->actingAs($studentUser, 'sanctum');
        $this->getJson("/api/student/lessons/{$scheduledLesson->id}/preview-link")->assertForbidden();
        $this->getJson("/api/student/lessons/{$scheduledLesson->id}/download-link")->assertForbidden();
        $this->postJson("/api/student/lessons/{$scheduledLesson->id}/view")->assertForbidden();

        $scheduledLesson->update(['status' => 'published', 'published_at' => now()]);

        $link = $this->getJson("/api/student/lessons/{$scheduledLesson->id}/preview-link")
            ->assertOk()
            ->json('data.url');

        $pathOnly = parse_url($link, PHP_URL_PATH) . '?' . parse_url($link, PHP_URL_QUERY);
        $preview = $this->get($pathOnly)->assertOk();
        $disposition = (string) $preview->headers->get('Content-Disposition');
        $this->assertStringNotContainsString('..', $disposition);
        $this->assertSame('inline; filename="evil_quote.pdf"', $disposition);
    }

    public function test_student_accounts_generate_unique_random_temp_passwords_and_hide_temp_password_on_user(): void
    {
        Mail::fake();
        $section = Section::create(['name' => 'Rizal', 'grade_level' => 'Grade 1', 'is_active' => true]);
        $admin = User::create([
            'name' => 'Admin',
            'email' => 'admin@example.test',
            'username' => 'admin',
            'password' => 'secret123',
            'role' => 'admin',
        ]);

        $this->actingAs($admin, 'sanctum');
        $first = $this->postJson('/api/student-accounts', [
            'student_number' => 'STU-2026-9101',
            'first_name' => 'Alice',
            'last_name' => 'Santos',
            'grade' => 'Grade 1',
            'section_id' => $section->id,
            'email' => 'alice.santos@example.test',
        ])->assertCreated();
        $second = $this->postJson('/api/student-accounts', [
            'student_number' => 'STU-2026-9102',
            'first_name' => 'Bob',
            'last_name' => 'Reyes',
            'grade' => 'Grade 1',
            'section_id' => $section->id,
            'email' => 'bob.reyes@example.test',
        ])->assertCreated();

        $pw1 = $first->json('data.default_password');
        $pw2 = $second->json('data.default_password');
        $this->assertNotSame('sdapass123#', $pw1);
        $this->assertNotSame($pw1, $pw2);

        $user1 = User::findOrFail($first->json('data.id'));
        $this->assertArrayNotHasKey('temp_password', $user1->toArray());

        $this->actingAs($user1, 'sanctum')
            ->postJson('/api/student/change-password', [
                'current_password' => $pw1,
                'password' => 'NewSecurePass99',
                'password_confirmation' => 'NewSecurePass99',
            ])->assertOk();

        $user1->refresh();
        $this->assertNull($user1->temp_password);
        $this->assertFalse($user1->is_first_login);
        $this->assertTrue(Hash::check('NewSecurePass99', $user1->password));

        // Admin reset generates a fresh random password distinct from the first one and sdapass123#
        $reset = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/student-accounts/{$user1->id}/reset-password")
            ->assertOk();
        $pwReset = $reset->json('data.default_password');
        $this->assertNotEmpty($pwReset);
        $this->assertNotSame('sdapass123#', $pwReset);
        $this->assertNotSame($pw1, $pwReset);

        $user1->refresh();
        $this->assertTrue($user1->is_first_login);
        $this->assertTrue(Hash::check($pwReset, $user1->password));
        $this->assertSame($pwReset, $user1->temp_password);
    }
}
