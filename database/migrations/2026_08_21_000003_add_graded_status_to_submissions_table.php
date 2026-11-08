<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE submissions MODIFY status ENUM('in_progress', 'submitted', 'incomplete', 'graded') NOT NULL DEFAULT 'in_progress'");
        }
    }

    public function down(): void
    {
        DB::table('submissions')->where('status', 'graded')->update(['status' => 'submitted']);
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE submissions MODIFY status ENUM('in_progress', 'submitted', 'incomplete') NOT NULL DEFAULT 'in_progress'");
        }
    }
};
