<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('users', 'two_factor_secret')) {
            return;
        }

        match (DB::getDriverName()) {
            'mysql' => DB::statement('ALTER TABLE users MODIFY two_factor_secret TEXT NULL'),
            'pgsql' => DB::statement('ALTER TABLE users ALTER COLUMN two_factor_secret TYPE TEXT'),
            default => null,
        };
    }

    public function down(): void
    {
        // Keep the wider column: existing encrypted secrets may exceed 255 characters.
    }
};
