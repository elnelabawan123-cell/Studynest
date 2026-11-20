<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\AdminSetting;
use App\Models\Lesson;
use App\Models\StaffNotification;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StaffNotificationsAndUploadLimitsTest extends TestCase
{
    use RefreshDatabase;

    public function test_teacher_receives_lesson_view_download_submission_and_due_unanswered_notifications_and_can_dismiss_or_clear_them(): void
    {
        $teacherUser = User::create([
            'name'     => 'Benjo Solis',
            'email'    => 'solis@example.test',
            'username' => 'solis.SDA',
            'password' => 'teacher-password',
            'role'     => 'teacher',
        ]);
        $teacher = Teacher::create([
            'name'          => 'Benjo Solis',
            'email'         => 'solis@example.test',
            'subject'       => 'Science',
            'grade_level'   => 'Grade 2',
            'classes_count' => 1,
            'status'        => 'Active',
        ]);
        $subject = Subject::create([
            'code'        => 'SCI-2',
            'name'        => 'Science',
            'grade_level' => 'Grade 2',
            'units'       => 1,
            'status'      => 'Active',
        ]);
        $subject->teachers()->attach($teacher->id);

        $studentUser1 = User::create([
            'name'     => 'Juan Cruz',
            'email'    => 'juan@example.test',
            'username' => 'cruz.SDA',
            'password' => 'student-password',
            'role'     => 'student',
        ]);
        $student1 = Student::create([
            'user_id'        => $studentUser1->id,
            'student_number' => 'STU-2026-0001',
            'first_name'     => 'Juan',
            'last_name'      => 'Cruz',
            'email'          => 'juan@example.test',
            'grade'          => 'Grade 2',
            'status'         => 'Active',
        ]);
        $studentUser2 = User::create([
            'name'     => 'Maria Santos',
            'email'    => 'maria@example.test',
            'username' => 'santos.SDA',
            'password' => 'student-password',
            'role'     => 'student',
        ]);
        $student2 = Student::create([
            'user_id'        => $studentUser2->id,
            'student_number' => 'STU-2026-0002',
            'first_name'     => 'Maria',
            'last_name'      => 'Santos',
            'email'          => 'maria@example.test',
            'grade'          => 'Grade 2',
            'status'         => 'Active',
        ]);
        $subject->students()->attach([$student1->id, $student2->id]);

        $lesson = Lesson::create([
            'teacher_id'   => $teacherUser->id,
            'subject_id'   => $subject->id,
            'title'        => 'Earth Layers',
            'type'         => 'PDF',
            'status'       => 'published',
            'published_at' => now()->subHour(),
        ]);

        // Student 1 views and downloads lesson
        $this->actingAs($studentUser1, 'sanctum')
            ->postJson("/api/student/lessons/{$lesson->id}/view")
            ->assertOk();
        $this->postJson("/api/student/lessons/{$lesson->id}/download")
            ->assertOk();

        // Past-due activity with neither student answering yet -> teacher alerted
        $activity = Activity::create([
            'teacher_id'         => $teacherUser->id,
            'subject_id'         => $subject->id,
            'type'               => 'Quiz',
            'title'              => 'Planet Quiz',
            'status'             => 'Open',
            'open_date'          => now()->subDays(2)->format('Y-m-d'),
            'open_time'          => '08:00',
            'due_date'           => now()->subHours(2)->format('Y-m-d'),
            'due_time'           => now()->subHours(2)->format('H:i'),
            'total_points'       => 10,
            'allow_text_answer'  => true,
            'allow_photo_answer' => false,
            'time_limit'         => 15,
        ]);

        $listResponse = $this->actingAs($teacherUser, 'sanctum')
            ->getJson('/api/teacher/notifications')
            ->assertOk();

        $items = collect($listResponse->json('data'));
        $this->assertTrue($items->contains(fn ($n) => $n['type'] === 'lesson_view' && str_contains($n['message'], 'Earth Layers')));
        $this->assertTrue($items->contains(fn ($n) => $n['type'] === 'lesson_download' && str_contains($n['message'], 'Earth Layers')));
        $this->assertTrue($items->contains(fn ($n) => $n['type'] === 'deadline' && str_contains($n['title'], 'Due Date Reached') && str_contains($n['message'], 'Juan Cruz')));

        // Ensure clean UTF-8 without mojibake
        foreach ($items as $item) {
            $this->assertStringNotContainsString('ðŸ', $item['title']);
            $this->assertStringNotContainsString('â€”', $item['message']);
        }

        // Individual mark read + dismiss persists across reloads
        $viewNotif = $items->firstWhere('type', 'lesson_view');
        $this->postJson("/api/teacher/notifications/{$viewNotif['id']}/read")
            ->assertOk()
            ->assertJsonPath('data.notification.is_read', true);

        $this->deleteJson("/api/teacher/notifications/{$viewNotif['id']}")
            ->assertOk()
            ->assertJsonPath('data.dismissed', true);

        $afterDismiss = collect($this->getJson('/api/teacher/notifications')->assertOk()->json('data'));
        $this->assertFalse($afterDismiss->contains(fn ($n) => $n['id'] === $viewNotif['id']));

        // Another user cannot dismiss this teacher's notifications
        $otherTeacher = User::create([
            'name'     => 'Other Teacher',
            'email'    => 'other@example.test',
            'username' => 'other.SDA',
            'password' => 'teacher-password',
            'role'     => 'teacher',
        ]);
        $dlNotif = $afterDismiss->firstWhere('type', 'lesson_download');
        $this->actingAs($otherTeacher, 'sanctum')
            ->deleteJson("/api/teacher/notifications/{$dlNotif['id']}")
            ->assertForbidden();

        // Clear all clears remaining notifications permanently
        $this->actingAs($teacherUser, 'sanctum')
            ->postJson('/api/teacher/notifications/clear-all')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 0);

        $afterClear = $this->getJson('/api/teacher/notifications')->assertOk()->json('data');
        $this->assertCount(0, $afterClear);
        $this->assertNotNull($activity->id);
    }

    public function test_admin_receives_notifications_when_teacher_registration_setting_enabled_and_request_submitted(): void
    {
        AdminSetting::set('teacher_registration', false);
        AdminSetting::set('email_notifications', false);

        $admin = User::create([
            'name'     => 'Admin User',
            'email'    => 'admin@example.test',
            'username' => 'admin.SDA',
            'password' => 'admin-password',
            'role'     => 'admin',
        ]);

        // Turning ON teacher_registration notifies admin
        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/settings', ['teacher_registration' => true])
            ->assertOk();

        // Public submits a teacher registration request -> notifies admin immediately
        $this->postJson('/api/teacher-registration-requests', [
            'first_name'            => 'Clara',
            'last_name'             => 'Reyes',
            'email'                 => 'clara.reyes@example.test',
            'grade_level'           => 'Grade 4',
            'password'              => 'secret-pass123',
            'password_confirmation' => 'secret-pass123',
        ])->assertCreated();

        $adminNotifs = collect(
            $this->actingAs($admin, 'sanctum')
                ->getJson('/api/admin/notifications')
                ->assertOk()
                ->json('data')
        );

        $this->assertTrue($adminNotifs->contains(fn ($n) => $n['type'] === 'setting' && str_contains($n['title'], 'Teacher Registration Enabled')));
        $regNotif = $adminNotifs->firstWhere('type', 'teacher_registration');
        $this->assertNotNull($regNotif);
        $this->assertStringContainsString('Clara Reyes', $regNotif['message']);

        // Approving clears the pending registration alert
        $regId = \DB::table('teacher_registration_requests')->where('email', 'clara.reyes@example.test')->value('id');
        $this->postJson("/api/teacher-registration-requests/{$regId}/approve")->assertOk();
        $this->assertDatabaseMissing('staff_notifications', [
            'user_id'          => $admin->id,
            'notification_key' => "teacher_reg_pending:{$regId}",
        ]);

        // Admin clear-all works cleanly
        $this->postJson('/api/admin/notifications/clear-all')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 0);
        $this->assertSame(0, StaffNotification::where('user_id', $admin->id)->active()->count());
    }

    public function test_upload_limiters_return_clear_exceeded_messages_for_oversized_photos_videos_and_documents(): void
    {
        Storage::fake('public');

        $teacherUser = User::create([
            'name'     => 'Upload Teacher',
            'email'    => 'upload@example.test',
            'username' => 'upload.SDA',
            'password' => 'teacher-password',
            'role'     => 'teacher',
        ]);
        $teacher = Teacher::create([
            'name'          => 'Upload Teacher',
            'email'         => 'upload@example.test',
            'subject'       => 'Math',
            'grade_level'   => 'Grade 1',
            'classes_count' => 1,
            'status'        => 'Active',
        ]);
        $subject = Subject::create([
            'code'        => 'MATH-1',
            'name'        => 'Math',
            'grade_level' => 'Grade 1',
            'units'       => 1,
            'status'      => 'Active',
        ]);
        $subject->teachers()->attach($teacher->id);

        // > 5 MB photo
        $bigPhoto = UploadedFile::fake()->create('huge-photo.png', 6 * 1024, 'image/png');
        $this->actingAs($teacherUser, 'sanctum')
            ->post('/api/teacher/lessons', [
                'title'        => 'Photo Lesson',
                'subject_id'   => $subject->id,
                'status'       => 'scheduled',
                'scheduled_at' => now()->addDay()->toIso8601String(),
                'file'         => $bigPhoto,
            ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'The file "huge-photo.png" exceeds the 5 MB limit (photos must be 5MB or smaller).']);

        // > 50 MB video
        $bigVideo = UploadedFile::fake()->create('huge-video.mp4', 55 * 1024, 'video/mp4');
        $this->post('/api/teacher/media/upload', ['file' => $bigVideo], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'The file "huge-video.mp4" exceeds the 50 MB limit (videos must be 50MB or smaller).']);

        // > 100 MB document
        $bigDoc = UploadedFile::fake()->create('huge-deck.pdf', 105 * 1024, 'application/pdf');
        $this->post('/api/teacher/lessons', [
            'title'        => 'Doc Lesson',
            'subject_id'   => $subject->id,
            'status'       => 'scheduled',
            'scheduled_at' => now()->addDay()->toIso8601String(),
            'file'         => $bigDoc,
        ], ['Accept' => 'application/json'])
            ->assertStatus(422);
    }
}
