<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Removes the "draft" status entirely and replaces it with automatic
 * scheduling: every lesson is now either "scheduled" or "published".
 *
 * A lesson becomes "published" automatically once `scheduled_at` is
 * reached (see App\Console\Commands\PublishScheduledLessons), so there
 * is no manual publish step anymore.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Step 1: widen the enum so existing 'draft' rows remain valid while we migrate them.
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE lessons MODIFY COLUMN status ENUM('draft','scheduled','published') NOT NULL DEFAULT 'scheduled'");
        }

        // Step 2: add the scheduling column.
        Schema::table('lessons', function (Blueprint $table) {
            $table->timestamp('scheduled_at')->nullable()->after('status');
        });

        // Step 3: migrate any existing drafts. We don't know an intended publish
        // date for old drafts, so schedule them "immediately" — the very next
        // run of the publish-scheduled-lessons command will publish them.
        DB::table('lessons')
            ->where('status', 'draft')
            ->update([
                'status'       => 'scheduled',
                'scheduled_at' => now(),
            ]);

        // Step 4: drop 'draft' from the enum now that no rows use it.
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE lessons MODIFY COLUMN status ENUM('scheduled','published') NOT NULL DEFAULT 'scheduled'");
        }

        Schema::table('lessons', function (Blueprint $table) {
            $table->index(['status', 'scheduled_at']);
        });
    }

    public function down(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            $table->dropIndex(['status', 'scheduled_at']);
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE lessons MODIFY COLUMN status ENUM('draft','scheduled','published') NOT NULL DEFAULT 'draft'");
        }

        DB::table('lessons')->where('status', 'scheduled')->update(['status' => 'draft']);

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE lessons MODIFY COLUMN status ENUM('draft','published') NOT NULL DEFAULT 'draft'");
        }

        Schema::table('lessons', function (Blueprint $table) {
            $table->dropColumn('scheduled_at');
        });
    }
};