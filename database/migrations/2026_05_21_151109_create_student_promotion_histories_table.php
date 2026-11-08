<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_promotion_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('school_year_id')->constrained()->cascadeOnDelete();
            $table->string('from_grade', 50);
            $table->string('to_grade',   50);
            $table->enum('result', ['promoted', 'graduated', 'skipped']);
            $table->timestamp('promoted_at');
            $table->timestamps();

            $table->unique(['student_id', 'school_year_id']); // prevent duplicates
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_promotion_histories');
    }
};