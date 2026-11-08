<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row in a teacher's "who submitted?" roster for an activity.
 * Shape: { id, studentId, studentName, status, autoScore, manualScore,
 *          totalScore, needsManualReview, submittedAt, gradedAt }
 */
class SubmissionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                 => $this->id,
            'studentId'          => $this->student?->id ?? $this->student_id,
            'studentName'        => $this->student?->full_name ?? 'Unknown student',
            'status'             => $this->status, // in_progress | submitted | incomplete
            'autoScore'          => (int) $this->auto_score,
            'manualScore'        => (int) $this->manual_score,
            'totalScore'         => (int) $this->total_score,
            'needsManualReview'  => (bool) $this->needs_manual_review,
            'startedAt'          => optional($this->started_at)->toISOString(),
            'submittedAt'        => optional($this->submitted_at)->toISOString(),
            'gradedAt'           => optional($this->graded_at)->toISOString(),
        ];
    }
}