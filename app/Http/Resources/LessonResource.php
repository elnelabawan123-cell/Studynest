<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Shape mirrors the React `Lesson` interface in LessonPage.tsx:
 *   { id, title, description, subject, grade, type, status,
 *     views, downloads, progress, fileName?, fileSize?, uploadedAt?, file_url? }
 */
class LessonResource extends JsonResource
{
   public function toArray(Request $request): array
{
    return [
        'id'          => $this->id,
        'title'       => $this->title,
        'description' => $this->description ?? '',

        'subject'     => $this->subject?->name ?? '',
        'grade'       => $this->subject?->grade_level ?? '',
        'subject_id'  => $this->subject_id,

        'type'        => $this->type,
        'status'      => $this->status,

        'views'       => (int) $this->views,
        'downloads'   => (int) $this->downloads,
        'progress'    => (int) $this->progress,

        'fileName'    => $this->file_name,
        'fileSize'    => $this->when(
            $this->file_size,
            fn () => $this->humanFileSize((int) $this->file_size)
        ),
        'file_url'    => $this->file_url,
        'mime_type'   => $this->mime_type,

        'uploadedAt'    => optional($this->created_at)->format('M j, Y'),

        // Raw ISO timestamps — the frontend needs these (not pre-formatted
        // strings) to calculate countdowns and "published X ago" text.
        'scheduled_at'  => optional($this->scheduled_at)->toISOString(),
        'published_at'  => optional($this->published_at)->toISOString(),
    ];
}

    private function humanFileSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1024 * 1024) {
            return number_format($bytes / 1024, 1) . ' KB';
        }
        return number_format($bytes / (1024 * 1024), 1) . ' MB';
    }
}