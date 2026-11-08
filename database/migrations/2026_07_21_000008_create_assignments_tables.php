<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Proposed — only run this if you don't already have an assignments
        // table elsewhere in Laravel. Mirrors lessons: teacher_id is what
        // determines "the subject this student is enrolled in" visibility.
        Schema::create('assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lesson_id')->nullable()->constrained(); // optional link to a lesson
            $table->string('grade');
            $table->foreignId('subject_id')->constrained();
            $table->foreignId('teacher_id')->constrained('users');
            $table->string('title');
            $table->text('instructions')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->unsignedInteger('points')->default(100);
            $table->enum('status', ['draft', 'published'])->default('draft');
            $table->timestamps();

            $table->index(['grade', 'subject_id', 'status']);
        });

        Schema::create('assignment_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assignment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('users');
            $table->text('response')->nullable();
            $table->string('attachment_url')->nullable();
            $table->unsignedInteger('score')->nullable(); // graded by teacher, nullable until graded
            $table->enum('status', ['not_started', 'submitted', 'graded'])->default('not_started');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();

            $table->unique(['assignment_id', 'student_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assignment_submissions');
        Schema::dropIfExists('assignments');
    }
};
