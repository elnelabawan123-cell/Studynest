<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // teacher_id already exists on `lessons` (added by an earlier
        // migration — possibly the original create_lessons_table itself).
        // Guard so this migration is a safe no-op instead of erroring.
        if (Schema::hasColumn('lessons', 'teacher_id')) {
            return;
        }

        Schema::table('lessons', function (Blueprint $table) {
            $table->foreignId('teacher_id')->nullable()->after('subject_id')->constrained('users');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('lessons', 'teacher_id')) {
            return;
        }

        Schema::table('lessons', function (Blueprint $table) {
            $table->dropForeign(['teacher_id']);
            $table->dropColumn('teacher_id');
        });
    }
};
