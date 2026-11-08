<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run: php artisan migrate
     * Creates teacher_student pivot table for many-to-many relationship
     * between teachers and students.
     *
     * A student can be assigned to multiple teachers (e.g. Math teacher, Science teacher).
     * A teacher can have multiple students in their class.
     */
    public function up(): void
    {
        Schema::create('teacher_student', function (Blueprint $table) {
            $table->id();

            $table->foreignId('teacher_id')
                  ->constrained('teachers')
                  ->cascadeOnDelete(); // remove assignment if teacher deleted

            $table->foreignId('student_id')
                  ->constrained('students')
                  ->cascadeOnDelete(); // remove assignment if student deleted

            $table->timestamps();

            // A student can only be assigned once per teacher
            $table->unique(['teacher_id', 'student_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_student');
    }
};