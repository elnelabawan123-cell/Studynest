<?php

namespace Tests\Feature;

use App\Models\Section;
use App\Models\Student;
use App\Services\StudentBulkImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentBulkImportDuplicateValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_bulk_review_rejects_existing_student_id_name_and_email(): void
    {
        $section = Section::create([
            'name' => 'Maple',
            'grade_level' => 'Grade 4',
            'is_active' => true,
        ]);
        Student::create([
            'student_number' => 'stu-2026-0042',
            'first_name' => 'Jamie',
            'middle_name' => 'L',
            'last_name' => 'Student',
            'email' => 'jamie@example.test',
            'grade' => 'Grade 4',
            'section_id' => $section->id,
            'gpa' => 0,
            'status' => 'Active',
        ]);

        $result = (new StudentBulkImportService())->validateRecords([[
            'student_number' => 'STU-2026-0042',
            'first_name' => 'jamie',
            'middle_name' => 'l',
            'last_name' => 'student',
            'suffix' => '',
            'grade' => 'Grade 4',
            'section' => 'Maple',
            'email' => 'JAMIE@example.test',
            '_row' => 2,
        ]]);

        $this->assertCount(0, $result['valid']);
        $this->assertCount(1, $result['invalid']);
        $this->assertContains('This Student Number already exists in the system.', $result['invalid'][0]['errors']);
        $this->assertContains('This Student Name already exists in the system.', $result['invalid'][0]['errors']);
        $this->assertContains('This Email already exists in the system.', $result['invalid'][0]['errors']);
    }

    public function test_bulk_review_marks_repeated_values_even_when_the_first_row_is_invalid(): void
    {
        Section::create([
            'name' => 'Maple',
            'grade_level' => 'Grade 4',
            'is_active' => true,
        ]);
        $record = [
            'student_number' => 'STU-2026-0043',
            'first_name' => 'Alex',
            'middle_name' => '',
            'last_name' => 'Learner',
            'suffix' => '',
            'grade' => 'Grade 4',
            'email' => 'alex@example.test',
        ];

        $result = (new StudentBulkImportService())->validateRecords([
            $record + ['section' => '', '_row' => 2],
            $record + ['section' => 'Maple', '_row' => 3],
        ]);

        $this->assertCount(0, $result['valid']);
        $this->assertCount(2, $result['invalid']);
        $this->assertContains('Duplicate Student Number within this file.', $result['invalid'][1]['errors']);
        $this->assertContains('Duplicate Student Name within this file.', $result['invalid'][1]['errors']);
        $this->assertContains('Duplicate Email within this file.', $result['invalid'][1]['errors']);
    }
}
