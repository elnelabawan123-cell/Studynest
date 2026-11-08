<?php

namespace Database\Seeders;

use App\Models\SchoolYear;
use Illuminate\Database\Seeder;

class SchoolYearSeeder extends Seeder
{
    public function run(): void
    {
        // Remove any existing active school year first
        SchoolYear::where('is_active', true)->update(['is_active' => false]);

        SchoolYear::firstOrCreate(
            ['label' => 'SY. 2026-2027'],
            [
                'start_date'  => '2026-06-01',
                'end_date'    => '2027-03-31',   // admin can edit this anytime
                'is_active'   => true,
                'promoted'    => false,
                'promoted_at' => null,
            ]
        );
    }
}