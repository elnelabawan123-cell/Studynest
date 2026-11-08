<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('submissions', function (Blueprint $table) {
            if (!Schema::hasColumn('submissions', 'current_question_index')) {
                $table->unsignedSmallInteger('current_question_index')->default(0)->after('status');
            }
            if (!Schema::hasColumn('submissions', 'completion_percentage')) {
                $table->unsignedTinyInteger('completion_percentage')->default(0)->after('current_question_index');
            }
            if (!Schema::hasColumn('submissions', 'last_activity_at')) {
                $table->timestamp('last_activity_at')->nullable()->after('completion_percentage');
            }
            if (!Schema::hasColumn('submissions', 'is_late')) {
                $table->boolean('is_late')->default(false)->after('submitted_at');
            }
        });
    }

    public function down(): void
    {
        // Left empty on purpose
    }
};