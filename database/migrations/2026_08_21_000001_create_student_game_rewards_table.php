<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_game_rewards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->string('game_key');
            $table->string('title');
            $table->string('subject')->default('Grade 1');
            $table->string('emoji', 20)->default('⭐');
            $table->unsignedTinyInteger('stars');
            $table->timestamps();

            $table->unique(['student_id', 'game_key']);
            $table->index(['student_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_game_rewards');
    }
};
