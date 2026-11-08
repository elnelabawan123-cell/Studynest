<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Deactivate any existing active school year
        DB::table('school_years')->where('is_active', true)->update(['is_active' => false]);

        // Insert SY. 2026-2027 only if it doesn't already exist
        $exists = DB::table('school_years')->where('label', 'SY. 2026-2027')->exists();

        if (! $exists) {
            DB::table('school_years')->insert([
                'label'       => 'SY. 2026-2027',
                'start_date'  => '2026-06-01',
                'end_date'    => '2027-03-31',   // admin can edit via the end-date endpoint
                'is_active'   => true,
                'promoted'    => false,
                'promoted_at' => null,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('school_years')->where('label', 'SY. 2026-2027')->delete();
    }
};