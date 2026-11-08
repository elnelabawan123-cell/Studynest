<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('school_years', function (Blueprint $table) {
            $table->id();
            $table->string('label', 50);            // e.g. "S.Y. 2026–2027"
            $table->date('start_date');              // set once, not editable after start
            $table->date('end_date');               // editable by admin
            $table->boolean('is_active')->default(false);
            $table->boolean('promoted')->default(false); // true once promotion ran
            $table->timestamp('promoted_at')->nullable();
            $table->timestamps();
        });

        // Seed the current school year
        $now   = now();
        $month = (int) $now->format('n');
        $year  = (int) $now->format('Y');

        // School year starts June, ends March the following year
        $startYear = $month >= 6 ? $year : $year - 1;
        $endYear   = $startYear + 1;

        DB::table('school_years')->insert([
            'label'      => "S.Y. {$startYear}–{$endYear}",
            'start_date' => "{$startYear}-06-01",
            'end_date'   => "{$endYear}-03-31",
            'is_active'  => true,
            'promoted'   => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('school_years');
    }
};