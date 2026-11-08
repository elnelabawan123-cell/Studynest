<?php

namespace App\Services;

use App\Models\AdminSetting;
use App\Models\Section;
use App\Models\Student;
use App\Models\User;

/**
 * Parses a .docx bulk-upload file (see the downloadable template) and
 * validates every row before any accounts are created.
 *
 * Usage:
 *   $service  = new StudentBulkImportService();
 *   $records  = $service->parse($absolutePathToDocx);
 *   $result   = $service->validateRecords($records); // ['valid' => [...], 'invalid' => [...]]
 */
class StudentBulkImportService
{
    /** Normalized header text → internal field key. Add synonyms here if needed. */
    private const HEADER_MAP = [
        'student number'  => 'student_number',
        'student no'      => 'student_number',
        'student no.'     => 'student_number',
        'student id'      => 'student_number',
        'full name'       => 'full_name',
        'name'            => 'full_name',
        'first name'      => 'first_name',
        'middle name'     => 'middle_name',
        'last name'       => 'last_name',
        'suffix'          => 'suffix',
        'grade'           => 'grade',
        'grade level'     => 'grade',
        'section'         => 'section',
        'email'           => 'email',
        'email address'   => 'email',
    ];

    private const REQUIRED_FIELDS = ['student_number', 'first_name', 'last_name', 'grade', 'email'];

    /**
     * Reads the first table found in the .docx and returns an array of
     * associative rows keyed by field name (student_number, first_name, ...).
     * The first table row is always treated as the header row.
     *
     * @throws \RuntimeException if no table / no header row can be found
     */
    public function parse(string $absolutePath): array
    {
        $rawRows = $this->readFirstTableRows($absolutePath);

        if (empty($rawRows)) {
            throw new \RuntimeException('No table was found in the uploaded document. Please use the provided template.');
        }

        $headerRow = array_map(fn ($h) => trim(strtolower($h)), array_shift($rawRows));
        $columnMap = [];
        foreach ($headerRow as $i => $header) {
            $columnMap[$i] = self::HEADER_MAP[$header] ?? null;
        }

        if (! in_array('student_number', $columnMap, true) && ! in_array('first_name', $columnMap, true)) {
            throw new \RuntimeException('The table headers do not match the expected template. Please download and use the provided template.');
        }

        $records = [];
        foreach ($rawRows as $rowIndex => $cells) {
            $isBlank = count(array_filter($cells, fn ($c) => trim((string) $c) !== ''
                && ! in_array(trim((string) $c), ['Select grade', 'Select section'], true))) === 0;
            if ($isBlank) {
                continue;
            }

            $record = [
                'student_number' => null, 'first_name' => null, 'middle_name' => null,
                'last_name' => null, 'suffix' => null, 'full_name' => null, 'grade' => null,
                'section' => null, 'email' => null,
            ];
            foreach ($cells as $i => $value) {
                $key = $columnMap[$i] ?? null;
                if ($key !== null) {
                    $value = trim((string) $value);
                    if (($key === 'grade' && $value === 'Select grade')
                        || ($key === 'section' && $value === 'Select section')) {
                        $value = '';
                    }
                    $record[$key] = $value;
                }
            }
            if ($record['full_name'] !== null && $record['first_name'] === null && $record['last_name'] === null) {
                $nameParts = preg_split('/\s+/u', trim($record['full_name']), -1, PREG_SPLIT_NO_EMPTY) ?: [];
                $record['first_name'] = array_shift($nameParts) ?? '';
                $record['last_name'] = implode(' ', $nameParts);
            }
            unset($record['full_name']);

            // Human row number: +1 for the header row, +1 to make it 1-indexed.
            $record['_row'] = $rowIndex + 2;
            $records[] = $record;
        }

        return $records;
    }

    /**
     * Reads cell text directly from WordprocessingML so values inside Word
     * content-control dropdowns are included as well as ordinary paragraphs.
     *
     * @return array<int, array<int, string>>
     */
    private function readFirstTableRows(string $absolutePath): array
    {
        $archive = new \ZipArchive();
        if ($archive->open($absolutePath) !== true) {
            throw new \RuntimeException('The uploaded Word document could not be opened.');
        }

        $documentXml = $archive->getFromName('word/document.xml');
        $archive->close();
        if (! is_string($documentXml)) {
            throw new \RuntimeException('The uploaded Word document has no document content.');
        }

        $document = new \DOMDocument();
        if (! $document->loadXML($documentXml, LIBXML_NONET)) {
            throw new \RuntimeException('The uploaded Word document contains invalid document content.');
        }

        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        $tables = $xpath->query('/w:document/w:body/w:tbl[1]');
        $table = $tables?->item(0);
        if (! $table) {
            return [];
        }

        $rows = [];
        foreach ($xpath->query('./w:tr', $table) ?: [] as $row) {
            $cells = [];
            foreach ($xpath->query('./w:tc', $row) ?: [] as $cell) {
                $text = '';
                foreach ($xpath->query('.//w:t', $cell) ?: [] as $textNode) {
                    $text .= $textNode->textContent;
                }
                $cells[] = trim($text);
            }
            $rows[] = $cells;
        }

        return $rows;
    }

    /**
     * @param  array<int, array<string, mixed>>  $records  Rows returned by parse()
     * @return array{valid: array<int, array<string, mixed>>, invalid: array<int, array<string, mixed>>}
     */
    public function validateRecords(array $records): array
    {
        $valid   = [];
        $invalid = [];
        $autoAssignSection = (bool) AdminSetting::get('auto_section_assignment', false);
        if ($autoAssignSection) {
            $sectionsByGrade = Section::query()
                ->where('is_active', true)
                ->withCount(['students as active_students_count' => fn ($query) => $query->where('status', 'Active')])
                ->get()
                ->groupBy('grade_level');
            $sectionLoads = [];
            foreach ($sectionsByGrade as $gradeSections) {
                foreach ($gradeSections as $gradeSection) {
                    $sectionLoads[$gradeSection->id] = $gradeSection->active_students_count;
                }
            }
        }

        // Track duplicates *within this file* so two rows can't both "win".
        $seenNumbers = [];
        $seenEmails  = [];
        $seenNames   = [];
        $existingNames = [];

        foreach (Student::query()->get(['first_name', 'middle_name', 'last_name', 'suffix']) as $student) {
            $profileName = trim(implode(' ', array_filter([
                $student->first_name,
                $student->middle_name,
                $student->last_name,
                $student->suffix,
            ])));
            if ($profileName !== '') {
                $existingNames[$this->normalizeName($profileName)] = true;
            }
        }
        foreach (User::where('role', 'student')->pluck('name') as $name) {
            $existingNames[$this->normalizeName($name)] = true;
        }

        foreach ($records as $record) {
            $errors = [];

            $requiredFields = self::REQUIRED_FIELDS;
            if (!$autoAssignSection) {
                $requiredFields[] = 'section';
            }
            foreach ($requiredFields as $field) {
                if (empty($record[$field])) {
                    $errors[] = ucwords(str_replace('_', ' ', $field)) . ' is required.';
                }
            }

            if (! empty($record['student_number']) && ! preg_match('/^[A-Za-z0-9\-]+$/', $record['student_number'])) {
                $errors[] = 'Student Number can only contain letters, numbers, and hyphens.';
            }

            if (! empty($record['email']) && ! filter_var($record['email'], FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Invalid email format.';
            }

            $section = null;
            if ($autoAssignSection && !empty($record['grade'])) {
                if (empty($sectionsByGrade[$record['grade']])) {
                    $errors[] = "No active section is available for grade \"{$record['grade']}\".";
                }
            } elseif (!empty($record['grade']) && !empty($record['section'])) {
                $section = Section::where('name', $record['section'])
                    ->where('grade_level', $record['grade'])
                    ->first();
                if (! $section) {
                    $errors[] = "Section \"{$record['section']}\" was not found for grade \"{$record['grade']}\".";
                }
            }

            $studentNumber = strtoupper(trim((string) ($record['student_number'] ?? '')));
            $email         = strtolower(trim((string) ($record['email'] ?? '')));
            $fullName      = trim(implode(' ', array_filter([
                $record['first_name'] ?? null,
                $record['middle_name'] ?? null,
                $record['last_name'] ?? null,
                $record['suffix'] ?? null,
            ])));
            $normalizedName = $this->normalizeName($fullName);

            if ($studentNumber !== '') {
                if (isset($seenNumbers[$studentNumber])) {
                    $errors[] = 'Duplicate Student Number within this file.';
                } elseif (Student::whereRaw('UPPER(student_number) = ?', [$studentNumber])->exists()) {
                    $errors[] = 'This Student Number already exists in the system.';
                }
            }

            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                if (isset($seenEmails[$email])) {
                    $errors[] = 'Duplicate Email within this file.';
                } elseif (
                    Student::whereRaw('LOWER(email) = ?', [$email])->exists()
                    || User::whereRaw('LOWER(email) = ?', [$email])->exists()
                ) {
                    $errors[] = 'This Email already exists in the system.';
                }
            }

            if ($normalizedName !== '') {
                if (isset($seenNames[$normalizedName])) {
                    $errors[] = 'Duplicate Student Name within this file.';
                } elseif (isset($existingNames[$normalizedName])) {
                    $errors[] = 'This Student Name already exists in the system.';
                }
            }

            if ($studentNumber !== '') {
                $seenNumbers[$studentNumber] = true;
            }
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $seenEmails[$email] = true;
            }
            if ($normalizedName !== '') {
                $seenNames[$normalizedName] = true;
            }

            if (! empty($errors)) {
                $invalid[] = array_merge($record, ['errors' => $errors]);
                continue;
            }

            if ($autoAssignSection) {
                $section = $sectionsByGrade[$record['grade']]
                    ->sort(function (Section $left, Section $right) use ($sectionLoads): int {
                        $loadDifference = $sectionLoads[$left->id] <=> $sectionLoads[$right->id];
                        return $loadDifference !== 0 ? $loadDifference : strcasecmp($left->name, $right->name);
                    })
                    ->first();
                $sectionLoads[$section->id]++;
            }

            $valid[] = array_merge($record, [
                'student_number' => $studentNumber,
                'email'          => $email,
                'section'        => $section->name,
                'section_id'     => $section->id,
            ]);
        }

        return ['valid' => $valid, 'invalid' => $invalid];
    }

    private function normalizeName(string $name): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim($name)) ?? '');
    }

}