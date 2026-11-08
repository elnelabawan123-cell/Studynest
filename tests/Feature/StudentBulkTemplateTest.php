<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\StudentBulkController;
use App\Models\Section;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use ZipArchive;

class StudentBulkTemplateTest extends TestCase
{
    use RefreshDatabase;

    public function test_template_has_active_grade_and_section_dropdowns_and_simple_input_columns(): void
    {
        Section::create(['name' => 'Maple', 'grade_level' => 'Grade 4', 'is_active' => true]);
        Section::create(['name' => 'Cedar', 'grade_level' => 'Grade 5', 'is_active' => true]);
        Section::create(['name' => 'Archived', 'grade_level' => 'Grade 6', 'is_active' => false]);

        $response = (new StudentBulkController())->template();
        $filePath = $response->getFile()->getPathname();

        try {
            $archive = new ZipArchive();
            $this->assertTrue($archive->open($filePath) === true);
            $documentXml = $archive->getFromName('word/document.xml');
            $archive->close();

            $this->assertIsString($documentXml);
            $this->assertStringContainsString('Student ID', $documentXml);
            $this->assertStringContainsString('First Name', $documentXml);
            $this->assertStringContainsString('Middle Name', $documentXml);
            $this->assertStringContainsString('Last Name', $documentXml);
            $this->assertStringNotContainsString('Full Name', $documentXml);
            $this->assertStringContainsString('Email', $documentXml);
            $this->assertStringContainsString('Grade 4', $documentXml);
            $this->assertStringContainsString('Grade 5', $documentXml);
            $this->assertStringContainsString('Maple', $documentXml);
            $this->assertStringContainsString('Cedar', $documentXml);
            $this->assertStringNotContainsString('Archived', $documentXml);
            $this->assertStringContainsString('dropDownList', $documentXml);
        } finally {
            if (is_file($filePath)) {
                unlink($filePath);
            }
        }
    }
}
