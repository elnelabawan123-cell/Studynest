<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreActivityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $isQuiz = $this->input('type') === 'Quiz';

        return [
            'type'               => ['required', 'in:Quiz,Assignment,Activity'],
            'title'              => ['required', 'string', 'max:255'],
            'description'        => ['nullable', 'string', 'max:5000'],
            'subject_id'         => ['required', 'integer', 'exists:subjects,id'],

            'status'             => ['nullable', 'in:Open,Closed,Draft,Scheduled'],

            'open_date'          => ['required', 'date'],
            'open_time'          => ['required', 'date_format:H:i'],
            'due_date'           => ['required', 'date', 'after_or_equal:open_date'],
            'due_time'           => ['required', 'date_format:H:i'],

            'total_points'       => ['required', 'integer', 'min:1', 'max:10000'],

            'allow_photo_answer' => ['boolean'],
            'allow_text_answer'  => ['boolean'],
            'attachment_path'    => ['nullable', 'string'],
            'attachment_type'    => ['nullable', 'in:image,pdf,video,presentation'],

            'time_limit'         => [$isQuiz ? 'required' : 'nullable', 'integer', 'min:1', 'max:600'],

            // Quiz questions array — required only when type is Quiz
            'quiz_questions'                     => [$isQuiz ? 'required' : 'nullable', 'array', 'min:1'],
            'quiz_questions.*.question'          => ['required_with:quiz_questions', 'string', 'max:2000'],
            'quiz_questions.*.answer_type'       => ['required_with:quiz_questions', 'in:Multiple Choice,Text,Photo'],
            'quiz_questions.*.checking_mode'     => ['nullable', 'in:Auto,Manual'],
            'quiz_questions.*.choices'           => ['nullable', 'array'],
            'quiz_questions.*.choices.*'         => ['nullable', 'string', 'max:500'],
            'quiz_questions.*.correct_answer'    => ['nullable', 'string', 'max:500'],
            'quiz_questions.*.points'            => ['required_with:quiz_questions', 'integer', 'min:1', 'max:1000'],
            'quiz_questions.*.attachment_path'   => ['nullable', 'string'],
            'quiz_questions.*.attachment_type'   => ['nullable', 'in:image,pdf,video,presentation'],
        ];
    }
}