<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ─── Teacher Activity Log ──────────────────────────────────────
        // Logs every "creation" a teacher does: lesson uploaded/published,
        // quiz/assignment/task created. Used by the Weekly Teacher Activity
        // Report and Subject Report.
        Schema::create('teacher_activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teacher_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('subject_id')->nullable()->constrained('subjects')->nullOnDelete();

            // Loose link to the source row (lessons.id, activities.id, etc.)
            // Nullable so logs survive if the source is deleted.
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('reference_type')->nullable(); // 'lesson' | 'activity' | etc.

            // 'lesson_uploaded' | 'lesson_published' | 'lesson_drafted'
            // | 'assignment_created' | 'task_created' | 'quiz_created'
            $table->string('activity_type');

            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status')->nullable(); // 'Published' | 'Draft' | 'Open' | 'Closed' | 'Scheduled'

            $table->timestamps();

            $table->index(['teacher_id', 'created_at']);
            $table->index(['subject_id', 'created_at']);
            $table->index('activity_type');
        });

        // ─── Lesson Tracking ───────────────────────────────────────────
        // One row per student-lesson interaction. Used by all reports
        // that show "Lessons Viewed/Downloaded".
        Schema::create('lesson_tracking', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('lesson_id')->constrained('lessons')->cascadeOnDelete();
            $table->foreignId('subject_id')->nullable()->constrained('subjects')->nullOnDelete();

            // 'view' | 'download'
            $table->string('action_type');

            $table->timestamps();

            $table->index(['student_id', 'subject_id']);
            $table->index(['lesson_id', 'action_type']);
        });

        // ─── Student Activity Submissions ──────────────────────────────
        // One row per student-activity submission. Used by all reports
        // that show assignment/task/quiz status + scores.
        Schema::create('student_activity_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('subject_id')->nullable()->constrained('subjects')->nullOnDelete();
            $table->foreignId('activity_id')->constrained('activities')->cascadeOnDelete();

            // 'Quiz' | 'Assignment' | 'Activity'
            $table->string('activity_type');

            // 'Submitted' | 'Pending' | 'Missing' | 'Late'
            $table->string('status')->default('Pending');

            $table->decimal('score', 6, 2)->nullable();

            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();

            $table->unique(['student_id', 'activity_id']); // one submission per student per activity
            $table->index(['subject_id', 'status']);
            $table->index('submitted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_activity_submissions');
        Schema::dropIfExists('lesson_tracking');
        Schema::dropIfExists('teacher_activity_logs');
    }
};
