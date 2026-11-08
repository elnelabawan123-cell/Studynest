<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateLessonRequest extends FormRequest
{
    public function authorize(): bool
    {
        $lesson = $this->route('lesson');
        return $lesson && $this->user() && $lesson->teacher_id === $this->user()->id;
    }

    public function rules(): array
    {
        return [
            'title'       => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'subject_id'  => ['sometimes', 'required', 'integer', 'exists:subjects,id'],
            'type'        => ['sometimes', 'in:Slides,PPT,Video,PDF,Image'],

            // Scheduling can only be changed while the lesson is still
            // "scheduled" — the controller enforces read-only once published.
            'status'       => ['sometimes', 'in:scheduled,published'],
            'scheduled_at' => ['sometimes', 'nullable', 'date'],

            'file'        => [
                'nullable',
                'file',
                'max:102400',
                'mimes:jpg,jpeg,png,webp,ppt,pptx,doc,docx,pdf,mp4,mov,avi,key',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'file.max'   => 'Files must be 100MB or smaller. Photos are limited to 5MB and videos to 50MB.',
            'file.mimes' => 'Allowed formats: PPT, PPTX, DOCX, PDF, MP4, KEY.',
        ];
    }
}