<?php

namespace Tests\Feature;

use App\Models\AdminSetting;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use App\Services\AdminAlertMailer;
use App\Services\StudentBulkImportService;
use Tests\TestCase;

class EnrollmentAndRegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_teacher_registration_requires_approval_before_the_applicant_can_sign_in(): void
    {
        AdminSetting::set('teacher_registration', true);
        AdminSetting::set('email_notifications', false);

        $this->postJson('/api/teacher-registration-requests', [
            'first_name' => 'Taylor',
            'last_name' => 'Teacher',
            'email' => 'taylor@example.test',
            'password' => 'teacher-password',
            'password_confirmation' => 'teacher-password',
        ])->assertCreated()
            ->assertJsonPath('message', 'Registration request submitted. An administrator must approve it before you can sign in.');

        $registration = \DB::table('teacher_registration_requests')->where('email', 'taylor@example.test')->first();
        $this->assertNotNull($registration);
        $this->assertSame('pending', $registration->status);
        $this->assertNotSame('teacher-password', $registration->password);
        $this->assertDatabaseMissing('users', ['email' => 'taylor@example.test']);
        $this->postJson('/api/login', [
            'identifier' => 'taylor@example.test',
            'password' => 'teacher-password',
        ])->assertUnauthorized();

        $admin = User::create([
            'name' => 'Admin',
            'email' => 'admin@example.test',
            'username' => 'admin',
            'password' => 'admin-password',
            'role' => 'admin',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/teacher-registration-requests/{$registration->id}/approve")
            ->assertOk()
            ->assertJsonPath('message', 'Teacher registration approved.');

        $this->assertDatabaseHas('teacher_registration_requests', [
            'id' => $registration->id,
            'status' => 'approved',
            'password' => null,
        ]);
        $this->assertDatabaseHas('teachers', ['email' => 'taylor@example.test', 'status' => 'Active']);
        $this->assertDatabaseHas('users', ['email' => 'taylor@example.test', 'role' => 'teacher']);

        $username = User::where('email', 'taylor@example.test')->value('username');
        $this->postJson('/api/login', [
            'identifier' => $username,
            'password' => 'teacher-password',
        ])->assertOk()->assertJsonPath('data.user.role', 'teacher');
    }

    public function test_teacher_registration_setting_blocks_public_applications_and_admin_can_reject_pending_requests(): void
    {
        AdminSetting::set('teacher_registration', false);
        $payload = [
            'first_name' => 'Riley',
            'last_name' => 'Applicant',
            'email' => 'riley@example.test',
            'password' => 'teacher-password',
            'password_confirmation' => 'teacher-password',
        ];
        $this->postJson('/api/teacher-registration-requests', $payload)->assertForbidden();

        AdminSetting::set('teacher_registration', true);
        AdminSetting::set('email_notifications', false);
        $this->postJson('/api/teacher-registration-requests', $payload)->assertCreated();
        $registrationId = \DB::table('teacher_registration_requests')->where('email', $payload['email'])->value('id');
        $admin = User::create([
            'name' => 'Admin',
            'email' => 'admin@example.test',
            'username' => 'admin',
            'password' => 'admin-password',
            'role' => 'admin',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/teacher-registration-requests/{$registrationId}/reject")
            ->assertOk();

        $this->assertDatabaseHas('teacher_registration_requests', [
            'id' => $registrationId,
            'status' => 'rejected',
            'password' => null,
        ]);
        $this->assertDatabaseMissing('users', ['email' => $payload['email']]);
    }

    public function test_student_self_enrollment_is_limited_to_active_subjects_for_their_grade(): void
    {
        AdminSetting::set('student_self_enrollment', true);
        AdminSetting::set('email_notifications', false);

        $user = User::create([
            'name' => 'Student',
            'email' => 'student@example.test',
            'username' => 'student',
            'password' => 'student-password',
            'role' => 'student',
        ]);
        $student = Student::create([
            'user_id' => $user->id,
            'student_number' => 'STU-2026-0001',
            'first_name' => 'Student',
            'last_name' => 'Example',
            'email' => 'student@example.test',
            'grade' => 'Grade 3',
            'section_id' => null,
            'gpa' => 0,
            'status' => 'Active',
        ]);
        $eligible = Subject::create([
            'code' => 'MATH-3',
            'name' => 'Mathematics',
            'grade_level' => 'Grade 3',
            'units' => 1,
            'status' => 'Active',
        ]);
        $wrongGrade = Subject::create([
            'code' => 'SCI-4',
            'name' => 'Science',
            'grade_level' => 'Grade 4',
            'units' => 1,
            'status' => 'Active',
        ]);
        $inactive = Subject::create([
            'code' => 'READ-3',
            'name' => 'Reading',
            'grade_level' => 'Grade 3',
            'units' => 1,
            'status' => 'Inactive',
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/student/subjects/available')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $eligible->id);

        $this->postJson("/api/student/subjects/{$eligible->id}/enroll")
            ->assertOk()
            ->assertJsonPath('data.subject.id', $eligible->id);
        $this->assertDatabaseHas('subject_student', [
            'student_id' => $student->id,
            'subject_id' => $eligible->id,
        ]);

        $this->postJson("/api/student/subjects/{$eligible->id}/enroll")->assertStatus(409);
        $this->postJson("/api/student/subjects/{$wrongGrade->id}/enroll")->assertForbidden();
        $this->postJson("/api/student/subjects/{$inactive->id}/enroll")->assertNotFound();

        AdminSetting::set('student_self_enrollment', false);
        $this->getJson('/api/student/subjects/available')->assertForbidden();
    }

    public function test_auto_section_assignment_uses_the_least_populated_active_section_and_alphabetical_tie_break(): void
    {
        Mail::fake();
        AdminSetting::set('auto_section_assignment', true);
        $sectionB = Section::create(['name' => 'B Section', 'grade_level' => 'Grade 2', 'is_active' => true]);
        $sectionA = Section::create(['name' => 'A Section', 'grade_level' => 'Grade 2', 'is_active' => true]);
        Section::create(['name' => 'Inactive Section', 'grade_level' => 'Grade 2', 'is_active' => false]);

        $admin = User::create([
            'name' => 'Admin',
            'email' => 'admin@example.test',
            'username' => 'admin',
            'password' => 'admin-password',
            'role' => 'admin',
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/student-accounts', [
                'student_number' => 'STU-2026-0200',
                'first_name' => 'New',
                'last_name' => 'Student',
                'grade' => 'Grade 2',
                'email' => 'new.student@example.test',
            ])
            ->assertCreated();

        $this->assertDatabaseHas('students', [
            'email' => 'new.student@example.test',
            'section_id' => $sectionA->id,
        ]);
        $this->assertDatabaseMissing('students', [
            'email' => 'new.student@example.test',
            'section_id' => $sectionB->id,
        ]);
    }

    public function test_bulk_student_validation_distributes_auto_assigned_rows_by_current_section_load(): void
    {
        AdminSetting::set('auto_section_assignment', true);
        $sectionB = Section::create(['name' => 'B Section', 'grade_level' => 'Grade 1', 'is_active' => true]);
        $sectionA = Section::create(['name' => 'A Section', 'grade_level' => 'Grade 1', 'is_active' => true]);

        $records = [
            [
                'student_number' => 'STU-2026-0301', 'first_name' => 'One', 'middle_name' => '',
                'last_name' => 'Student', 'suffix' => '', 'grade' => 'Grade 1', 'section' => '',
                'email' => 'one@example.test', '_row' => 2,
            ],
            [
                'student_number' => 'STU-2026-0302', 'first_name' => 'Two', 'middle_name' => '',
                'last_name' => 'Student', 'suffix' => '', 'grade' => 'Grade 1', 'section' => '',
                'email' => 'two@example.test', '_row' => 3,
            ],
        ];

        $result = (new StudentBulkImportService())->validateRecords($records);

        $this->assertCount(2, $result['valid']);
        $this->assertSame([], $result['invalid']);
        $this->assertSame([$sectionA->id, $sectionB->id], array_column($result['valid'], 'section_id'));
    }

    public function test_admin_email_alerts_are_sent_when_the_setting_is_enabled(): void
    {
        AdminSetting::set('email_notifications', true);
        User::create([
            'name' => 'Admin',
            'email' => 'admin@example.test',
            'username' => 'admin',
            'password' => 'admin-password',
            'role' => 'admin',
        ]);

        Mail::shouldReceive('raw')
            ->once()
            ->withArgs(fn (string $body, callable $callback) => str_contains($body, 'A new request')
                && is_callable($callback));

        $result = app(AdminAlertMailer::class)->send('New request', 'A new request');

        $this->assertSame(['status' => 'sent', 'delivered' => 1], $result);
    }
}
