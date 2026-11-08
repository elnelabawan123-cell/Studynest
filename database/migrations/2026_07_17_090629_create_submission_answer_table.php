<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('submission_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('submission_id')->constrained('submissions')->cascadeOnDelete();
            $table->foreignId('quiz_question_id')->constrained('quiz_questions')->cascadeOnDelete();

            // Only one of these three is populated, depending on the question's answer_type.
            $table->string('selected_choice')->nullable();   // Multiple Choice
            $table->text('text_answer')->nullable();         // Short Answer
            $table->string('photo_path')->nullable();        // Photo Upload

            // Auto-graded for Multiple Choice / Short Answer at submit time.
            // Left null for Photo Upload until a teacher grades it manually.
            $table->boolean('is_correct')->nullable();
            $table->unsignedInteger('points_awarded')->nullable();

            // Manual-grading-only fields (Photo Upload).
            $table->text('teacher_feedback')->nullable();
            $table->timestamp('graded_at')->nullable();

            $table->timestamps();

            $table->unique(['submission_id', 'quiz_question_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('submission_answers');
    }
};