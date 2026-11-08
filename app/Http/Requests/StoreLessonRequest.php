<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreLessonRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Any authenticated teacher can create a lesson.
        // Refine with a Gate/Policy if you have non-teacher roles.
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'title'       => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'subject_id'  => ['required', 'integer', 'exists:subjects,id'],
            'type'        => ['nullable', 'in:Slides,PPT,Video,PDF,Image'],

            // Draft no longer exists — a lesson is either scheduled (default)
            // or published immediately.
            'status'       => ['nullable', 'in:scheduled,published'],
            'scheduled_at' => ['required_if:status,scheduled', 'nullable', 'date'],

            // File is optional on create — teacher may save details first,
            // then upload during edit (only while still "scheduled").
            // Aggregate limit; smaller photo/video limits are enforced by LessonController.
            'file'        => [
                'nullable',
                'file',
                'max:102400', // 100 MB in KB
                'mimes:jpg,jpeg,png,webp,ppt,pptx,doc,docx,pdf,mp4,mov,avi,key',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'file.max'                 => 'Files must be 100MB or smaller. Photos are limited to 5MB and videos to 50MB.',
            'file.mimes'               => 'Allowed formats: JPG, PNG, WEBP, PPT, PPTX, DOCX, PDF, MP4, MOV, AVI, KEY.',
            'scheduled_at.required_if' => 'Please choose a publish date and time.',
        ];
    }
}