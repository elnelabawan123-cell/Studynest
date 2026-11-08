<?php

namespace Tests\Feature;

use App\Mail\TeacherCredentialsMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

class AccountsManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    public function test_account_list_does_not_expose_usernames_or_temporary_passwords(): void
    {
        $admin = User::create([
            'name' => 'Test Admin',
            'email' => 'admin@example.test',
            'username' => 'test-admin',
            'password' => Hash::make('admin-password'),
            'role' => 'admin',
        ]);
        User::create([
            'name' => 'Test Teacher',
            'email' => 'teacher@example.test',
            'username' => 'private.teacher',
            'password' => Hash::make('teacher-password'),
            'temp_password' => 'teacher-password',
            'role' => 'teacher',
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/accounts')
            ->assertOk();

        $response->assertJsonMissing(['username' => 'private.teacher'])
            ->assertJsonMissing(['temp_password' => 'teacher-password'])
            ->assertJsonMissing(['password' => 'teacher-password']);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/teacher-accounts')
            ->assertOk()
            ->assertJsonMissing(['username' => 'private.teacher']);
    }

    public function test_admin_password_reset_emails_new_password_without_returning_it(): void
    {
        Mail::fake();
        $admin = User::create([
            'name' => 'Test Admin',
            'email' => 'admin@example.test',
            'username' => 'test-admin',
            'password' => Hash::make('admin-password'),
            'role' => 'admin',
        ]);
        $teacher = User::create([
            'name' => 'Test Teacher',
            'email' => 'teacher@example.test',
            'username' => 'private.teacher',
            'password' => Hash::make('old-password'),
            'temp_password' => 'old-password',
            'role' => 'teacher',
            'is_first_login' => false,
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/accounts/{$teacher->id}/reset-password")
            ->assertOk()
            ->assertJsonPath('data.email_status', 'sent')
            ->assertJsonMissingPath('data.default_password')
            ->assertJsonMissingPath('data.username');

        $teacher->refresh();
        $this->assertFalse(Hash::check('old-password', $teacher->password));
        $this->assertTrue($teacher->is_first_login);
        $this->assertNull($teacher->temp_password);
        $this->assertStringNotContainsString('private.teacher', $response->getContent());
        $this->assertStringNotContainsString($teacher->password, $response->getContent());

        Mail::assertSent(TeacherCredentialsMail::class, fn (TeacherCredentialsMail $mail) => $mail->hasTo($teacher->email));
    }
}
