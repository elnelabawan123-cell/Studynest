<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->foreignId('school_year_id')->nullable()->after('section_id')
                  ->constrained('school_years')->nullOnDelete();
        });

        // Assign all existing students to the current active school year
        $activeId = DB::table('school_years')->where('is_active', true)->value('id');
        if ($activeId) {
            DB::table('students')->whereNull('school_year_id')->update(['school_year_id' => $activeId]);
        }
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropForeignIdFor(\App\Models\SchoolYear::class);
            $table->dropColumn('school_year_id');
        });
    }
};