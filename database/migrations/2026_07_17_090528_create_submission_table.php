<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('activity_id')->constrained('activities')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();

            // not_started is implicit (no row exists yet) — a row is only
            // created once a student opens or submits the activity, or once
            // the auto-close command flags them as incomplete.
            $table->enum('status', ['in_progress', 'submitted', 'incomplete'])->default('in_progress');

            // Sum of auto-graded (Multiple Choice + Short Answer) points.
            $table->unsignedInteger('auto_score')->default(0);
            // Sum of teacher-assigned points for Photo Upload questions.
            $table->unsignedInteger('manual_score')->default(0);
            // auto_score + manual_score, kept denormalized for fast list/reporting queries.
            $table->unsignedInteger('total_score')->default(0);

            // True whenever the submission has at least one Photo Upload
            // answer that the teacher hasn't graded yet.
            $table->boolean('needs_manual_review')->default(false);

            $table->timestamp('started_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('graded_at')->nullable();

            $table->timestamps();

            // One submission per student per activity.
            $table->unique(['activity_id', 'student_id']);
            $table->index(['activity_id', 'status']);
            $table->index(['activity_id', 'needs_manual_review']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('submissions');
    }
};