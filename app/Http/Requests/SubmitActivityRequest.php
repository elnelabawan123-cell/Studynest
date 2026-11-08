<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Consumed by the Flutter student app.
 *
 * Expected payload (multipart/form-data when any Photo answers are present):
 *   answers[0][quiz_question_id] = 12
 *   answers[0][selected_choice]  = "B"        (Multiple Choice)
 *   answers[1][quiz_question_id] = 13
 *   answers[1][text_answer]      = "Quezon City"   (Short Answer)
 *   answers[2][quiz_question_id] = 14
 *   answers[2][photo]            = <file>      (Photo Upload)
 */
class SubmitActivityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'answers'                         => ['required', 'array', 'min:1'],
            'answers.*.quiz_question_id'      => ['required', 'integer', 'exists:quiz_questions,id'],
            'answers.*.selected_choice'       => ['nullable', 'string', 'max:500'],
            'answers.*.text_answer'           => ['nullable', 'string', 'max:2000'],
            'answers.*.photo'                 => ['nullable', 'file', 'image', 'max:10240'], // 10 MB
        ];
    }
}