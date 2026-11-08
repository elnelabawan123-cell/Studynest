<?php

namespace App\Http\Controllers\Api;

use App\Models\SchoolYear;
use App\Models\Student;
use App\Models\StudentPromotionHistory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SchoolyearController extends BaseApiController
{
    const GRADE_MAP = [
        'Grade 1'  => 'Grade 2',
        'Grade 2'  => 'Grade 3',
        'Grade 3'  => 'Grade 4',
        'Grade 4'  => 'Grade 5',
        'Grade 5'  => 'Grade 6',
        // Grade 6 → graduated (handled separately)
    ];

    /**
     * GET /api/admin/school-year
     * Returns the active school year.
     */
    public function show(): JsonResponse
    {
        $sy = SchoolYear::active();

        if (! $sy) {
            return $this->error('No active school year found.', 404);
        }

        return $this->success($this->format($sy));
    }

    /**
     * GET /api/admin/school-years
     * List all school years.
     */
    public function index(): JsonResponse
    {
        $years = SchoolYear::orderByDesc('start_date')->get()->map(fn ($sy) => $this->format($sy));
        return $this->success($years);
    }

    /**
     * PUT /api/admin/school-year/end-date
     * Admin edits only the end date of the active school year.
     */
    public function updateEndDate(Request $request): JsonResponse
    {
        $sy = SchoolYear::active();
        if (! $sy) {
            return $this->error('No active school year found.', 404);
        }

        $data = $request->validate([
            'end_date' => 'required|date|after:' . $sy->start_date->format('Y-m-d'),
        ], [
            'end_date.required' => 'End date is required.',
            'end_date.date'     => 'Please enter a valid date.',
            'end_date.after'    => 'End date must be after the start date (' . $sy->start_date->format('M d, Y') . ').',
        ]);

        if ($sy->promoted) {
            return $this->error('Students have already been promoted for this school year. The end date can no longer be changed.', 422);
        }

        $sy->update(['end_date' => $data['end_date']]);

        return $this->success($this->format($sy->fresh()), 'End date updated successfully.');
    }

    /**
     * POST /api/admin/school-year/promote
     * Manually trigger promotion (for testing or emergency use).
     * The scheduler calls this logic automatically.
     */
    public function promote(): JsonResponse
    {
        $sy = SchoolYear::active();
        if (! $sy) {
            return $this->error('No active school year found.', 404);
        }

        if ($sy->promoted) {
            return $this->error(
                "Students have already been promoted for {$sy->label}. Promotion runs only once per school year.",
                422
            );
        }

        [$promoted, $graduated, $skipped, $nextSy] = $this->runPromotion($sy);

        return $this->success([
            'school_year'      => $sy->label,
            'promoted'         => $promoted,
            'graduated'        => $graduated,
            'skipped'          => $skipped,
            'next_school_year' => $nextSy ? $this->format($nextSy) : null,
        ], "Promotion complete for {$sy->label}. {$promoted} promoted, {$graduated} graduated. New school year {$nextSy?->label} created.");
    }

    /**
     * Core promotion logic — shared by controller and scheduler.
     * Returns [$promoted, $graduated, $skipped, $nextSchoolYear].
     */
    public static function runPromotion(SchoolYear $sy): array
    {
        $promoted  = 0;
        $graduated = 0;
        $skipped   = 0;
        $now       = Carbon::now();
        $nextSy    = null;

        DB::transaction(function () use ($sy, $now, &$promoted, &$graduated, &$skipped, &$nextSy) {
            // Only active, non-archived students in this school year
            $students = Student::where('status', 'Active')
                ->where('school_year_id', $sy->id)
                ->whereNotIn('id', function ($q) use ($sy) {
                    $q->select('student_id')
                      ->from('student_promotion_histories')
                      ->where('school_year_id', $sy->id);
                })
                ->get();

            foreach ($students as $student) {
                $fromGrade = $student->grade;

                if (isset(self::GRADE_MAP[$fromGrade])) {
                    $toGrade = self::GRADE_MAP[$fromGrade];
                    $student->update(['grade' => $toGrade]);
                    StudentPromotionHistory::create([
                        'student_id'     => $student->id,
                        'school_year_id' => $sy->id,
                        'from_grade'     => $fromGrade,
                        'to_grade'       => $toGrade,
                        'result'         => 'promoted',
                        'promoted_at'    => $now,
                    ]);
                    $promoted++;
                } elseif ($fromGrade === 'Grade 6') {
                    StudentPromotionHistory::create([
                        'student_id'     => $student->id,
                        'school_year_id' => $sy->id,
                        'from_grade'     => $fromGrade,
                        'to_grade'       => 'Graduated',
                        'result'         => 'graduated',
                        'promoted_at'    => $now,
                    ]);
                    $graduated++;
                } else {
                    $skipped++;
                }
            }

            // Mark current school year as promoted and deactivate it
            $sy->update([
                'promoted'    => true,
                'promoted_at' => $now,
                'is_active'   => false,   // ← deactivate old SY
            ]);

            // ── Create next school year automatically ──────────────────
            // start_date = June 1 of the year after end_date
            // e.g. SY 2026-2027 ends → next starts Jun 1, 2027 → SY. 2027-2028
            $nextStartYear = $sy->end_date->year;   // the year the current SY ends (e.g. 2027)
            $nextStart     = Carbon::create($nextStartYear, 6, 1)->startOfDay();
            $nextEnd       = Carbon::create($nextStartYear + 1, 3, 31)->startOfDay(); // default end
            $nextLabel     = 'SY. ' . $nextStartYear . '-' . ($nextStartYear + 1);

            // Only create if it doesn't already exist
            $exists = SchoolYear::where('label', $nextLabel)->exists();
            if (! $exists) {
                $nextSy = SchoolYear::create([
                    'label'      => $nextLabel,
                    'start_date' => $nextStart->toDateString(),
                    'end_date'   => $nextEnd->toDateString(),
                    'is_active'  => true,
                    'promoted'   => false,
                    'promoted_at'=> null,
                ]);
            } else {
                // If it was pre-created, just make sure it's active
                $nextSy = SchoolYear::where('label', $nextLabel)->first();
                $nextSy->update(['is_active' => true]);
            }
        });

        return [$promoted, $graduated, $skipped, $nextSy];
    }

    /**
     * GET /api/admin/school-year/promotion-history
     */
    public function promotionHistory(): JsonResponse
    {
        $sy = SchoolYear::active();
        if (! $sy) {
            return $this->success([]);
        }

        $history = StudentPromotionHistory::with('student:id,first_name,last_name')
            ->where('school_year_id', $sy->id)
            ->orderByDesc('promoted_at')
            ->take(50)
            ->get()
            ->map(fn ($h) => [
                'id'          => $h->id,
                'student'     => $h->student?->full_name ?? '—',
                'from_grade'  => $h->from_grade,
                'to_grade'    => $h->to_grade,
                'result'      => $h->result,
                'promoted_at' => $h->promoted_at?->format('M d, Y h:i A'),
            ]);

        return $this->success($history);
    }

    /**
     * POST /api/admin/school-year/create-next
     * Manually pre-create the next school year (optional admin action).
     */
    public function createNext(Request $request): JsonResponse
    {
        $sy = SchoolYear::active();
        if (! $sy) {
            return $this->error('No active school year found.', 404);
        }

        $nextStartYear = $sy->end_date->year;
        $nextLabel     = 'SY. ' . $nextStartYear . '-' . ($nextStartYear + 1);

        if (SchoolYear::where('label', $nextLabel)->exists()) {
            return $this->error("School year {$nextLabel} already exists.", 422);
        }

        $data = $request->validate([
            'end_date' => 'nullable|date',
        ]);

        $nextStart = Carbon::create($nextStartYear, 6, 1)->toDateString();
        $nextEnd   = $data['end_date']
            ?? Carbon::create($nextStartYear + 1, 3, 31)->toDateString();

        $next = SchoolYear::create([
            'label'      => $nextLabel,
            'start_date' => $nextStart,
            'end_date'   => $nextEnd,
            'is_active'  => false,   // not active yet; becomes active after promotion
            'promoted'   => false,
            'promoted_at'=> null,
        ]);

        return $this->success($this->format($next), "School year {$nextLabel} pre-created successfully.");
    }

    private function format(SchoolYear $sy): array
    {
        return [
            'id'             => $sy->id,
            'label'          => $sy->label,
            'start_date'     => $sy->start_date->format('Y-m-d'),
            'start_display'  => $sy->start_date->format('M d, Y'),
            'end_date'       => $sy->end_date->format('Y-m-d'),
            'end_display'    => $sy->end_date->format('M d, Y'),
            'is_active'      => $sy->is_active,
            'promoted'       => $sy->promoted,
            'promoted_at'    => $sy->promoted_at?->format('M d, Y'),
            'days_remaining' => $sy->promoted
                ? null
                : max(0, (int) now()->startOfDay()->diffInDays($sy->end_date->startOfDay(), false)),
        ];
    }
}