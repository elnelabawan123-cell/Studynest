<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            // Closing is now a deliberate teacher action, never a side effect
            // of due_date passing. resolveStatus() should check is_closed,
            // not compare due_date against now().
            $table->boolean('is_closed')->default(false)->after('status');
            $table->timestamp('closed_at')->nullable()->after('is_closed');
            $table->foreignId('closed_by')->nullable()->after('closed_at')
                ->constrained('users')->nullOnDelete();

            // Off by default — a student who has already submitted stays
            // submitted/graded unless the teacher flips this on.
            $table->boolean('allow_resubmission')->default(false)->after('closed_by');
        });
    }

    public function down(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->dropConstrainedForeignId('closed_by');
            $table->dropColumn(['is_closed', 'closed_at', 'allow_resubmission']);
        });
    }
};
