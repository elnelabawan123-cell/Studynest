<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('activities', 'attachment_path')) {
            Schema::table('activities', function (Blueprint $table) {
                $table->string('attachment_path')->nullable()->after('allow_text_answer');
            });
        }

        if (!Schema::hasColumn('activities', 'attachment_type')) {
            Schema::table('activities', function (Blueprint $table) {
                $table->string('attachment_type')->nullable()->after('attachment_path');
            });
        }

        if (!Schema::hasColumn('quiz_questions', 'attachment_path')) {
            Schema::table('quiz_questions', function (Blueprint $table) {
                $table->string('attachment_path')->nullable()->after('correct_answer');
            });
        }

        if (!Schema::hasColumn('quiz_questions', 'attachment_type')) {
            Schema::table('quiz_questions', function (Blueprint $table) {
                $table->string('attachment_type')->nullable()->after('attachment_path');
            });
        }

        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE activities MODIFY COLUMN attachment_type VARCHAR(255) NULL');
            DB::statement('ALTER TABLE quiz_questions MODIFY COLUMN attachment_type VARCHAR(255) NULL');
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('activities', 'attachment_path')) {
            Schema::table('activities', function (Blueprint $table) {
                $table->dropColumn('attachment_path');
            });
        }

        if (Schema::hasColumn('activities', 'attachment_type')) {
            Schema::table('activities', function (Blueprint $table) {
                $table->dropColumn('attachment_type');
            });
        }

        if (Schema::hasColumn('quiz_questions', 'attachment_path')) {
            Schema::table('quiz_questions', function (Blueprint $table) {
                $table->dropColumn('attachment_path');
            });
        }

        if (Schema::hasColumn('quiz_questions', 'attachment_type')) {
            Schema::table('quiz_questions', function (Blueprint $table) {
                $table->dropColumn('attachment_type');
            });
        }
    }
};
