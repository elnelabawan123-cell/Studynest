<?php

namespace Tests\Feature;

use App\Services\StudentBulkImportService;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use Tests\TestCase;

class StudentBulkImportWordDropdownTest extends TestCase
{
    public function test_word_dropdown_values_and_full_name_are_read_from_template_table(): void
    {
        $phpWord = new PhpWord();
        $table = $phpWord->addSection()->addTable();
        $table->addRow();
        foreach (['Student ID', 'Full Name', 'Email', 'Grade', 'Section'] as $header) {
            $table->addCell()->addText($header);
        }

        $table->addRow();
        $table->addCell()->addText('STU-2026-0044');
        $table->addCell()->addText('Alex Learner');
        $table->addCell()->addText('alex@example.test');
        $table->addCell()->addSDT('dropDownList')
            ->setValue('Grade 4')
            ->setListItems(['Select grade' => 'Select grade', 'Grade 4' => 'Grade 4']);
        $table->addCell()->addSDT('dropDownList')
            ->setValue('Maple')
            ->setListItems(['Select section' => 'Select section', 'Maple' => 'Maple']);

        $table->addRow();
        foreach ([' ', ' ', ' '] as $blank) {
            $table->addCell()->addText($blank);
        }
        $table->addCell()->addSDT('dropDownList')
            ->setValue('Select grade')
            ->setListItems(['Select grade' => 'Select grade', 'Grade 4' => 'Grade 4']);
        $table->addCell()->addSDT('dropDownList')
            ->setValue('Select section')
            ->setListItems(['Select section' => 'Select section', 'Maple' => 'Maple']);

        $tempPath = tempnam(sys_get_temp_dir(), 'student-bulk-');
        $docxPath = $tempPath . '.docx';
        @unlink($tempPath);

        try {
            IOFactory::createWriter($phpWord, 'Word2007')->save($docxPath);
            $records = (new StudentBulkImportService())->parse($docxPath);

            $this->assertCount(1, $records);
            $this->assertSame('Alex', $records[0]['first_name']);
            $this->assertSame('Learner', $records[0]['last_name']);
            $this->assertSame('Grade 4', $records[0]['grade']);
            $this->assertSame('Maple', $records[0]['section']);
        } finally {
            if (is_file($docxPath)) {
                unlink($docxPath);
            }
        }
    }

    public function test_word_template_reads_separate_first_middle_and_last_names(): void
    {
        $phpWord = new PhpWord();
        $table = $phpWord->addSection()->addTable();
        $table->addRow();
        foreach (['Student ID', 'First Name', 'Middle Name', 'Last Name', 'Email', 'Grade', 'Section'] as $header) {
            $table->addCell()->addText($header);
        }

        $table->addRow();
        foreach (['STU-2026-0045', 'Alex', 'Marie', 'Learner', 'alex@example.test'] as $value) {
            $table->addCell()->addText($value);
        }
        $table->addCell()->addSDT('dropDownList')
            ->setValue('Grade 4')
            ->setListItems(['Select grade' => 'Select grade', 'Grade 4' => 'Grade 4']);
        $table->addCell()->addSDT('dropDownList')
            ->setValue('Maple')
            ->setListItems(['Select section' => 'Select section', 'Maple' => 'Maple']);

        $tempPath = tempnam(sys_get_temp_dir(), 'student-bulk-');
        $docxPath = $tempPath . '.docx';
        @unlink($tempPath);

        try {
            IOFactory::createWriter($phpWord, 'Word2007')->save($docxPath);
            $records = (new StudentBulkImportService())->parse($docxPath);

            $this->assertCount(1, $records);
            $this->assertSame('Alex', $records[0]['first_name']);
            $this->assertSame('Marie', $records[0]['middle_name']);
            $this->assertSame('Learner', $records[0]['last_name']);
            $this->assertSame('Grade 4', $records[0]['grade']);
            $this->assertSame('Maple', $records[0]['section']);
        } finally {
            if (is_file($docxPath)) {
                unlink($docxPath);
            }
        }
    }
}
