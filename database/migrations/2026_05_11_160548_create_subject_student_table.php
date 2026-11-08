<?php


use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subject_student', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subject_id')
                  ->constrained('subjects')
                  ->cascadeOnDelete();
            $table->foreignId('student_id')
                  ->constrained('students')
                  ->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['subject_id', 'student_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subject_student');
    }
};