<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activities', function (Blueprint $table) {
            $table->id();

            // Ownership
            $table->foreignId('teacher_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->foreignId('subject_id')
                ->constrained('subjects')
                ->cascadeOnDelete();

            // Quiz | Assignment | Activity
            $table->enum('type', ['Quiz', 'Assignment', 'Activity'])->default('Assignment');

            $table->string('title');
            $table->text('description')->nullable();

            // Open | Closed | Draft | Scheduled
            $table->enum('status', ['Open', 'Closed', 'Draft', 'Scheduled'])->default('Draft');

            // Schedule windows (kept as date + time to mirror the frontend form)
            $table->date('open_date');
            $table->time('open_time')->default('08:00:00');
            $table->date('due_date');
            $table->time('due_time')->default('23:59:00');

            $table->unsignedInteger('total_points')->default(100);

            // Submission options (used by Assignment / Activity)
            $table->boolean('allow_photo_answer')->default(true);
            $table->boolean('allow_text_answer')->default(true);

            // Quiz-only
            $table->unsignedInteger('time_limit')->nullable(); // minutes

            $table->timestamps();

            $table->index(['teacher_id', 'status']);
            $table->index(['subject_id', 'status']);
            $table->index(['due_date', 'due_time']);
        });

        // ─── Quiz questions (only used when activities.type === 'Quiz') ──
        Schema::create('quiz_questions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('activity_id')
                ->constrained('activities')
                ->cascadeOnDelete();

            $table->unsignedInteger('order')->default(0);
            $table->text('question');

            // Multiple Choice | Text | Photo
            $table->enum('answer_type', ['Multiple Choice', 'Text', 'Photo'])
                ->default('Multiple Choice');

            // Multiple-choice options (array of strings)
            $table->json('choices')->nullable();

            // Stored only for auto-gradable types (Multiple Choice)
            $table->string('correct_answer')->nullable();

            $table->unsignedInteger('points')->default(10);

            $table->timestamps();

            $table->index(['activity_id', 'order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quiz_questions');
        Schema::dropIfExists('activities');
    }
};