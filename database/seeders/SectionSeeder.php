<?php
namespace Database\Seeders;

use App\Models\Section;
use Illuminate\Database\Seeder;

class SectionSeeder extends Seeder
{
    public function run(): void
    {
        $sections = [
            'Grade K' => 'Rizal',
            'Grade 1' => 'Bonifacio',
            'Grade 2' => 'Mabini',
            'Grade 3' => 'Luna',
            'Grade 4' => 'Aguinaldo',
            'Grade 5' => 'Rizal',
            'Grade 6' => 'Bonifacio',
        ];

        foreach ($sections as $grade => $name) {
            Section::firstOrCreate(
                ['name' => $name, 'grade_level' => $grade],
                ['is_active' => true]
            );
        }
    }
}