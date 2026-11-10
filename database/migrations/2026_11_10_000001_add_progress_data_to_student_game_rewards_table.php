<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_game_rewards', function (Blueprint $table) {
            $table->json('progress_data')->nullable()->after('attempts');
        });
    }

    public function down(): void
    {
        Schema::table('student_game_rewards', function (Blueprint $table) {
            $table->dropColumn('progress_data');
        });
    }
};

