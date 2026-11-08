<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateActivityRequest extends FormRequest
{
    public function authorize(): bool
    {
        $activity = $this->route('activity');
        return $activity && $this->user() && $activity->teacher_id === $this->user()->id;
    }

    public function rules(): array
    {
        $isQuiz = $this->input('type', optional($this->route('activity'))->type) === 'Quiz';

        return [
            'type'               => ['sometimes', 'in:Quiz,Assignment,Activity'],
            'title'              => ['sometimes', 'required', 'string', 'max:255'],
            'description'        => ['nullable', 'string', 'max:5000'],
            'subject_id'         => ['sometimes', 'required', 'integer', 'exists:subjects,id'],
            'status'             => ['sometimes', 'in:Open,Closed,Draft,Scheduled'],

            'open_date'          => ['sometimes', 'required', 'date'],
            'open_time'          => ['sometimes', 'required', 'date_format:H:i'],
            'due_date'           => ['sometimes', 'required', 'date', 'after_or_equal:open_date'],
            'due_time'           => ['sometimes', 'required', 'date_format:H:i'],

            'total_points'       => ['sometimes', 'integer', 'min:1', 'max:10000'],

            'allow_photo_answer' => ['boolean'],
            'allow_text_answer'  => ['boolean'],
            'attachment_path'    => ['nullable', 'string'],
            'attachment_type'    => ['nullable', 'in:image,pdf,video,presentation'],

            'time_limit'         => ['nullable', 'integer', 'min:1', 'max:600'],

            'quiz_questions'                     => [$isQuiz ? 'sometimes' : 'nullable', 'array'],
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