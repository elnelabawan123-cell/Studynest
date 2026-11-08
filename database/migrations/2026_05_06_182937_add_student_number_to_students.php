<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run: php artisan migrate
     * Adds auto-generated student_number (e.g. STU-2024-0001).
     * This is what the student types as their username in the Flutter app.
     */
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->string('student_number', 20)
                  ->nullable()
                  ->unique()
                  ->after('id')
                  ->comment('Auto-generated login ID, e.g. STU-2024-0001');
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropColumn('student_number');
        });
    }
};