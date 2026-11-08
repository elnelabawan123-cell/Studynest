<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_game_rewards', function (Blueprint $table) {
            // Existing rows are only ever written when stars > 0 (i.e. on
            // completion), so backfilling status='completed' matches
            // current behavior exactly.
            $table->string('status')->default('completed')->after('stars');
            $table->timestamp('completed_at')->nullable()->after('status');
            $table->unsignedInteger('attempts')->default(1)->after('completed_at');
        });

        DB::table('student_game_rewards')->update([
            'completed_at' => DB::raw('updated_at'),
        ]);
    }

    public function down(): void
    {
        Schema::table('student_game_rewards', function (Blueprint $table) {
            $table->dropColumn(['status', 'completed_at', 'attempts']);
        });
    }
};
