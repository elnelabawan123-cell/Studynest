<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->string('type')->default('string'); // string | boolean | json
            $table->string('description')->nullable();
            $table->timestamps();
        });

        // Seed default settings
        DB::table('admin_settings')->insert([
            ['key' => 'maintenance_mode',        'value' => 'false', 'type' => 'boolean', 'description' => 'Temporarily disable user access', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'two_factor_auth',         'value' => 'false', 'type' => 'boolean', 'description' => '2FA for admin login',              'created_at' => now(), 'updated_at' => now()],
            ['key' => 'school_year',             'value' => '2024-2025', 'type' => 'string', 'description' => 'Active school year',            'created_at' => now(), 'updated_at' => now()],
            ['key' => 'grading_system',          'value' => 'Percentage', 'type' => 'string', 'description' => 'Grading system in use',        'created_at' => now(), 'updated_at' => now()],
            ['key' => 'auto_section_assignment', 'value' => 'false', 'type' => 'boolean', 'description' => 'Auto-assign students to sections', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'teacher_registration',    'value' => 'false', 'type' => 'boolean', 'description' => 'Allow teacher self-registration',  'created_at' => now(), 'updated_at' => now()],
            ['key' => 'student_self_enrollment', 'value' => 'true',  'type' => 'boolean', 'description' => 'Let students enroll into subjects', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'admin_multi_login',       'value' => 'false', 'type' => 'boolean', 'description' => 'Allow multiple admin sessions',     'created_at' => now(), 'updated_at' => now()],
            ['key' => 'activity_logs_enabled',   'value' => 'true',  'type' => 'boolean', 'description' => 'Track all admin actions',           'created_at' => now(), 'updated_at' => now()],
            ['key' => 'email_notifications',     'value' => 'true',  'type' => 'boolean', 'description' => 'System alerts to admin email',      'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_settings');
    }
};