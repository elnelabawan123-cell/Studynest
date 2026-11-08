<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            // Drop old flat columns
            $table->dropColumn(['name', 'section']);

            // Structured name
            $table->string('first_name')->after('student_number');
            $table->string('middle_name')->nullable()->after('first_name');
            $table->string('last_name')->after('middle_name');
            $table->string('suffix', 20)->nullable()->after('last_name'); // Jr., Sr., III

            // Section FK (nullable so existing rows don't break)
            $table->unsignedBigInteger('section_id')->nullable()->after('grade');
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropForeign(['section_id']);
            $table->dropColumn(['first_name', 'middle_name', 'last_name', 'suffix', 'section_id']);
            $table->string('name')->after('student_number');
            $table->string('section')->after('grade');
        });
    }
};