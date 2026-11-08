<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add temp_password to users.
     *
     * Stores the plaintext temporary password only while is_first_login = true.
     * Cleared to NULL the moment the user completes their first login and
     * changes their password. Never exposed in general API responses —
     * only returned by the admin /pending-credentials endpoint.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('temp_password')->nullable()->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('temp_password');
        });
    }
};