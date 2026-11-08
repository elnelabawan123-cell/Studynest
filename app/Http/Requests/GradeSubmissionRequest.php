<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GradeSubmissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'answers'                    => ['required', 'array', 'min:1'],
            'answers.*.id'                => ['required', 'integer', 'exists:submission_answers,id'],
            'answers.*.points_awarded'    => ['nullable', 'integer', 'min:0'],
            'answers.*.teacher_feedback'  => ['nullable', 'string', 'max:2000'],
        ];
    }
}