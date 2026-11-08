<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')
            ->where('two_factor_enabled', true)
            ->whereNull('two_factor_secret')
            ->update(['two_factor_enabled' => false]);
    }

    public function down(): void
    {
        // The old flag did not represent a verified authenticator secret.
    }
};
