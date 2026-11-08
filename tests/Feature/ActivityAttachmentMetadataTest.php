<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ActivityAttachmentMetadataTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('username')->unique();
            $table->string('password');
            $table->string('role')->default('teacher');
            $table->timestamps();
        });

        Schema::create('subjects', function (Blueprint $table): void {
            $table->id();
            $table->string('code');
            $table->string('name');
            $table->string('grade_level');
            $table->string('status')->default('active');
            $table->timestamps();
        });

        Schema::create('activities', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('teacher_id');
            $table->unsignedBigInteger('subject_id');
            $table->string('type');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status');
            $table->date('open_date');
            $table->time('open_time')->default('08:00:00');
            $table->date('due_date');
            $table->time('due_time')->default('23:59:00');
            $table->unsignedInteger('total_points')->default(100);
            $table->boolean('allow_photo_answer')->default(true);
            $table->boolean('allow_text_answer')->default(true);
            $table->string('attachment_path')->nullable();
            $table->string('attachment_type')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('activities');
        Schema::dropIfExists('subjects');
        Schema::dropIfExists('users');

        parent::tearDown();
    }

    public function test_activity_can_persist_attachment_metadata(): void
    {
        $teacher = User::create([
            'name' => 'Teacher One',
            'email' => 'teacher@example.com',
            'username' => 'teacher.one',
            'password' => 'password123',
            'role' => 'teacher',
        ]);

        $subject = Subject::create([
            'code' => 'MATH',
            'name' => 'Mathematics',
            'grade_level' => 'Grade 4',
            'status' => 'active',
        ]);

        $activity = Activity::create([
            'teacher_id' => $teacher->id,
            'subject_id' => $subject->id,
            'type' => 'Assignment',
            'title' => 'Homework',
            'description' => 'Attach a reference file',
            'status' => 'Open',
            'open_date' => '2026-07-26',
            'open_time' => '08:00:00',
            'due_date' => '2026-07-30',
            'due_time' => '23:59:00',
            'total_points' => 100,
            'allow_photo_answer' => true,
            'allow_text_answer' => true,
            'attachment_path' => 'question-attachments/demo.jpg',
            'attachment_type' => 'image',
        ]);

        $this->assertSame('question-attachments/demo.jpg', $activity->fresh()->attachment_path);
        $this->assertSame('image', $activity->fresh()->attachment_type);

        $presentation = $activity->replicate()->fill([
            'attachment_path' => 'question-attachments/reference.pptx',
            'attachment_type' => 'presentation',
        ]);
        $presentation->save();

        $this->assertSame('question-attachments/reference.pptx', $presentation->fresh()->attachment_path);
        $this->assertSame('presentation', $presentation->fresh()->attachment_type);
    }
}
