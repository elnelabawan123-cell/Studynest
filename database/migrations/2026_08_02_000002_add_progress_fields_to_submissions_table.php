<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // All 4 columns this migration originally tried to add already
        // exist in your database — the previous run added them
        // successfully and only failed on the redundant unique()
        // constraint below (your submissions table already has one under
        // a different auto-generated name). This version is a no-op:
        // Schema::hasColumn guards mean it adds nothing, it just lets
        // Laravel mark this migration as complete so it stops retrying.
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
        // Deliberately NOT adding the unique(activity_id, student_id)
        // constraint — your submissions table already enforces this
        // under a different migration's auto-generated index name.
    }

    public function down(): void
    {
        // Left empty on purpose — since up() is now conditional, blindly
        // dropping these columns here could remove ones that predate
        // this migration entirely. Handle rollback manually if you ever
        // need to undo this.
    }
};