<?php

namespace Tests\Feature;

use App\Mail\StudentCredentialsMail;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentLoginIdentifierTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_can_sign_in_with_student_number(): void
    {
        $user = User::create([
            'name' => 'Jamie Student',
            'email' => 'jamie@example.test',
            'username' => 'student.username',
            'password' => 'temporary-password',
            'role' => 'student',
        ]);

        Student::create([
            'user_id' => $user->id,
            'student_number' => 'STU-2026-0042',
            'first_name' => 'Jamie',
            'last_name' => 'Student',
            'email' => $user->email,
            'grade' => 'Grade 1',
            'gpa' => 0,
            'status' => 'Active',
        ]);

        $this->postJson('/api/login', [
            'identifier' => 'stu-2026-0042',
            'password' => 'temporary-password',
        ])->assertOk()
            ->assertJsonPath('data.user.role', 'student');
    }

    public function test_student_credentials_email_uses_student_number_not_username(): void
    {
        $html = (new StudentCredentialsMail(
            'Jamie Student',
            'STU-2026-0042',
            'temporary-password',
        ))->render();

        $this->assertStringContainsString('Student ID', $html);
        $this->assertStringContainsString('STU-2026-0042', $html);
        $this->assertStringNotContainsString('Username:', $html);
    }
}
