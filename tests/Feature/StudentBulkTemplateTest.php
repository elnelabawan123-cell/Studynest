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

    public function test_principal_report_word_export_and_bulk_template_work_with_pclzip_fallback(): void
    {
        \PhpOffice\PhpWord\Settings::setZipClass(\PhpOffice\PhpWord\Settings::PCLZIP);

        Section::create(['name' => 'Faith & Hope', 'grade_level' => 'Grade 1', 'is_active' => true]);

        $templateResponse = (new StudentBulkController())->template();
        $templatePath = $templateResponse->getFile()->getPathname();

        try {
            $this->assertFileExists($templatePath);
            $this->assertGreaterThan(1000, filesize($templatePath));

            $req = \Illuminate\Http\Request::create(
                '/api/admin/reports/principal/export',
                'GET',
                ['report_type' => 'school_overview', 'format' => 'word']
            );
            $exportResponse = (new \App\Http\Controllers\Api\PrincipalReportController())->export($req);

            ob_start();
            $exportResponse->sendContent();
            $docxBytes = ob_get_clean();

            $this->assertIsString($docxBytes);
            $this->assertGreaterThan(1000, strlen($docxBytes));
            $this->assertSame('504b0304', bin2hex(substr($docxBytes, 0, 4)));
        } finally {
            if (is_file($templatePath)) {
                @unlink($templatePath);
            }
            \PhpOffice\PhpWord\Settings::setZipClass(\PhpOffice\PhpWord\Settings::ZIPARCHIVE);
        }
    }
}
