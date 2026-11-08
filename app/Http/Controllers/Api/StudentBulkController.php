<?php

namespace App\Http\Controllers\Api;

use App\Mail\StudentCredentialsMail;
use App\Models\Section;
use App\Models\SchoolYear;
use App\Models\Student;
use App\Models\User;
use App\Services\StudentBulkImportService;
use App\Traits\GeneratesStudentUsername;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use PhpOffice\PhpWord\IOFactory as PhpWordIOFactory;
use PhpOffice\PhpWord\PhpWord;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Bulk student-account creation via Word (.docx) upload.
 *
 * Flow:
 *   1. GET  /api/student-accounts/bulk-upload/template  → admin downloads a blank template
 *   2. POST /api/student-accounts/bulk-upload/preview    → admin uploads it, sees valid/invalid rows
 *   3. POST /api/student-accounts/bulk-upload/commit      → (same file) actually creates the valid rows
 *
 * Nothing here touches StudentAccountController's existing single-create flow.
 */
class StudentBulkController extends BaseApiController
{
    use GeneratesStudentUsername;

    private const DEFAULT_PASSWORD = 'sdapass123#';

    /** GET /api/student-accounts/bulk-upload/template */
    public function template(): BinaryFileResponse
    {
        $phpWord = new PhpWord();
        $section = $phpWord->addSection();
        $activeSections = Section::query()
            ->where('is_active', true)
            ->orderBy('grade_level')
            ->orderBy('name')
            ->get(['grade_level', 'name']);
        $grades = $activeSections->pluck('grade_level')->unique()->values()->all();
        $sectionNames = $activeSections->pluck('name')->unique()->values()->all();
        $gradePlaceholder = 'Select grade';
        $sectionPlaceholder = 'Select section';
        $gradeOptions = array_combine(
            array_merge([$gradePlaceholder], $grades),
            array_merge([$gradePlaceholder], $grades),
        );
        $sectionOptions = array_combine(
            array_merge([$sectionPlaceholder], $sectionNames),
            array_merge([$sectionPlaceholder], $sectionNames),
        );

        $section->addText('Bulk Student Account Upload Template', ['bold' => true, 'size' => 15]);
        $section->addText(
            'Enter the Student ID, First Name, Middle Name (if any), Last Name, and Email for each student. '
            . 'Choose Grade and Section using the dropdowns. Only active grades and sections are listed. '
            . 'Keep the header row exactly as-is. When automatic section assignment is enabled, Section is ignored.',
            ['size' => 9, 'color' => '555555']
        );
        $section->addTextBreak(1);

        $headers = ['Student ID', 'First Name', 'Middle Name', 'Last Name', 'Email', 'Grade', 'Section'];
        $columnWidths = [1000, 1500, 1500, 1500, 1800, 1400, 1400];

        $table = $section->addTable([
            'borderSize'  => 6,
            'borderColor' => '999999',
            'width'       => 100 * 50,
            'unit'        => 'pct',
        ]);

        $table->addRow();
        foreach ($headers as $index => $h) {
            $cell = $table->addCell($columnWidths[$index], ['bgColor' => 'EFF6FF']);
            $cell->addText($h, ['bold' => true, 'size' => 9]);
        }

        for ($i = 0; $i < 8; $i++) {
            $table->addRow();
            $table->addCell($columnWidths[0])->addText(' ');
            $table->addCell($columnWidths[1])->addText(' ');
            $table->addCell($columnWidths[2])->addText(' ');
            $table->addCell($columnWidths[3])->addText(' ');
            $table->addCell($columnWidths[4])->addText(' ');
            $table->addCell($columnWidths[5])
                ->addSDT('dropDownList')
                ->setAlias('Grade')
                ->setValue($gradePlaceholder)
                ->setListItems($gradeOptions);
            $table->addCell($columnWidths[6])
                ->addSDT('dropDownList')
                ->setAlias('Section')
                ->setValue($sectionPlaceholder)
                ->setListItems($sectionOptions);
        }

        $fileName = 'student-bulk-upload-template.docx';
        $tmpPath  = storage_path('app/tmp_' . uniqid('tpl_', true) . '.docx');

        $writer = PhpWordIOFactory::createWriter($phpWord, 'Word2007');
        $writer->save($tmpPath);

        return response()->download($tmpPath, $fileName)->deleteFileAfterSend(true);
    }

    /**
     * POST /api/student-accounts/bulk-upload/preview
     * Parses + validates the file WITHOUT creating any accounts, so the
     * admin can review errors before committing.
     */
    public function preview(Request $request): JsonResponse
    {
        $request->validate([
            'file' => [
                'required', 'file', 'max:10240', // 10 MB
                function ($attribute, $value, $fail) {
                    if (! $this->isDocx($value)) {
                        $fail('Please upload a Word (.docx) file.');
                    }
                },
            ],
        ]);

        [$records, $error] = $this->parseUploadedFile($request);
        if ($error) {
            return $this->error($error, 422);
        }

        if (empty($records)) {
            return $this->error('No student rows were found in the uploaded file.', 422);
        }

        $result = (new StudentBulkImportService())->validateRecords($records);

        return $this->success([
            'total_rows'    => count($records),
            'valid_count'   => count($result['valid']),
            'invalid_count' => count($result['invalid']),
            'valid'         => $result['valid'],
            'invalid'       => $result['invalid'],
        ]);
    }

    /**
     * POST /api/student-accounts/bulk-upload/commit
     * Re-parses and re-validates the same file (never trusts client-side
     * state for creation), then creates every valid row and emails
     * credentials to each new student.
     */
    public function commit(Request $request): JsonResponse
    {
        $request->validate([
            'file' => [
                'required', 'file', 'max:10240',
                function ($attribute, $value, $fail) {
                    if (! $this->isDocx($value)) {
                        $fail('Please upload a Word (.docx) file.');
                    }
                },
            ],
        ]);

        [$records, $error] = $this->parseUploadedFile($request);
        if ($error) {
            return $this->error($error, 422);
        }

        $service    = new StudentBulkImportService();
        $result     = $service->validateRecords($records);
        $schoolYear = SchoolYear::active();

        $created = [];
        $failed  = [];

        foreach ($result['valid'] as $record) {
            DB::beginTransaction();
            try {
                $fullName = trim(implode(' ', array_filter([
                    $record['first_name'], $record['middle_name'] ?: null,
                    $record['last_name'],  $record['suffix']      ?: null,
                ])));
                $username = $this->generateStudentUsername($record['last_name']);

                $user = User::create([
                    'name'           => $fullName,
                    'email'          => $record['email'],
                    'username'       => $username,
                    'password'       => Hash::make(self::DEFAULT_PASSWORD),
                    'role'           => 'student',
                    'is_first_login' => true,
                ]);

                $student = Student::create([
                    'user_id'        => $user->id,
                    'student_number' => $record['student_number'],
                    'first_name'     => $record['first_name'],
                    'middle_name'    => $record['middle_name'] ?: null,
                    'last_name'      => $record['last_name'],
                    'suffix'         => $record['suffix']      ?: null,
                    'email'          => $record['email'],
                    'grade'          => $record['grade'],
                    'section_id'     => $record['section_id'],
                    'school_year_id' => $schoolYear?->id,
                    'gpa'            => 0,
                    'status'         => 'Active',
                ]);

                DB::commit();

                $emailStatus = 'sent';
                $emailError  = null;
                try {
                    Mail::to($record['email'])->send(new StudentCredentialsMail($fullName, $student->student_number, self::DEFAULT_PASSWORD));
                } catch (\Throwable $mailEx) {
                    $emailStatus = 'failed';
                    $emailError  = $mailEx->getMessage();
                    Log::warning("Failed to email student credentials to {$record['email']} (user #{$user->id}): " . $mailEx->getMessage());
                }

                $created[] = [
                    'row'            => $record['_row'] ?? null,
                    'student_id'     => $student->id,
                    'student_number' => $student->student_number,
                    'name'           => $fullName,
                    'username'       => $username,
                    'email'          => $record['email'],
                    'grade'          => $record['grade'],
                    'email_status'   => $emailStatus,
                    'email_error'    => $emailError,
                ];
            } catch (\Throwable $e) {
                DB::rollBack();
                $failed[] = [
                    'row'            => $record['_row'] ?? null,
                    'student_number' => $record['student_number'] ?? null,
                    'name'           => trim(($record['first_name'] ?? '') . ' ' . ($record['last_name'] ?? '')),
                    'errors'         => ['Failed to create account: ' . $e->getMessage()],
                ];
            }
        }

        foreach ($result['invalid'] as $record) {
            $failed[] = [
                'row'            => $record['_row'] ?? null,
                'student_number' => $record['student_number'] ?? null,
                'name'           => trim(($record['first_name'] ?? '') . ' ' . ($record['last_name'] ?? '')),
                'errors'         => $record['errors'],
            ];
        }

        $emailFailures = count(array_filter($created, fn ($c) => $c['email_status'] === 'failed'));

        return $this->success([
            'created_count'       => count($created),
            'failed_count'        => count($failed),
            'email_failed_count'  => $emailFailures,
            'created'             => $created,
            'failed'              => $failed,
            'default_password'    => self::DEFAULT_PASSWORD,
        ], sprintf(
            '%d student account(s) created, %d failed.%s',
            count($created),
            count($failed),
            $emailFailures ? " {$emailFailures} credential email(s) failed to send — use the Accounts page to reset and email new credentials after resolving email delivery." : ''
        ));
    }

    /**
     * .docx files are internally zip archives — depending on the server's
     * fileinfo/magic database, PHP sometimes detects them as
     * application/zip instead of the "proper" Word MIME type, which makes
     * Laravel's built-in `mimes:docx` rule reject perfectly valid files.
     * Check the extension AND accept either MIME type instead.
     */
    private function isDocx(\Illuminate\Http\UploadedFile $file): bool
    {
        if (strtolower($file->getClientOriginalExtension()) !== 'docx') {
            return false;
        }

        $mime = $file->getMimeType();

        return in_array($mime, [
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/zip',
            'application/octet-stream',
        ], true);
    }

    /**
     * @return array{0: array<int, array<string,mixed>>, 1: ?string} [records, errorMessage]
     */
    private function parseUploadedFile(Request $request): array
    {
        $path = $request->file('file')->getRealPath();
        try {
            $records = (new StudentBulkImportService())->parse($path);
            return [$records, null];
        } catch (\Throwable $e) {
            return [[], $e->getMessage() ?: 'Could not read the uploaded file. Please use the provided template.'];
        }
    }
}