<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lesson_activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lesson_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->enum('type', ['view', 'download']);
            $table->timestamp('created_at')->useCurrent();

            // One row per (lesson, student, type) — repeated views/downloads
            // just bump `updated_at` instead of growing the table forever.
            // If you'd rather log every single occurrence, drop this unique
            // index and switch the upsert in the controller to a plain create().
            $table->timestamp('updated_at')->nullable();
            $table->unique(['lesson_id', 'student_id', 'type']);

            $table->index(['lesson_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_activity_logs');
    }
};
