<?php

namespace Tests\Feature;

use App\Models\Subject;
use App\Models\Student;
use App\Models\Activity;
use App\Models\Lesson;
use App\Models\QuizQuestion;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SubjectManagementImprovementsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    public function test_subject_names_are_case_insensitively_unique_within_a_grade(): void
    {
        $admin = User::create([
            'name' => 'admin user',
            'email' => 'admin@example.test',
            'username' => 'admin.user',
            'password' => 'password',
            'role' => 'admin',
        ]);
        Sanctum::actingAs($admin);
        Subject::create([
            'code' => 'MATH1',
            'name' => 'mathematics',
            'grade_level' => 'Grade 1',
            'units' => 1,
            'status' => 'Active',
        ]);

        $response = $this->postJson('/api/admin/subjects', [
            'code' => 'MATH2',
            'name' => '  MATHEMATICS  ',
            'grade_level' => 'Grade 1',
        ]);

        $response->assertUnprocessable()
            ->assertJsonPath('status', false);
        $this->assertSame(1, Subject::where('grade_level', 'Grade 1')->count());
    }

    public function test_subject_can_have_only_one_teacher_at_a_time(): void
    {
        $admin = User::create([
            'name' => 'admin user',
            'email' => 'admin@example.test',
            'username' => 'admin.user',
            'password' => 'password',
            'role' => 'admin',
        ]);
        Sanctum::actingAs($admin);
        $subject = Subject::create([
            'code' => 'SCI1',
            'name' => 'science',
            'grade_level' => 'Grade 1',
            'units' => 1,
            'status' => 'Active',
        ]);
        $teachers = collect(['one', 'two'])->map(fn (string $suffix) => Teacher::create([
            'name' => "teacher {$suffix}",
            'email' => "teacher.{$suffix}@example.test",
            'subject' => 'science',
            'grade_level' => 'Grade 1',
            'status' => 'Active',
        ]));

        $this->postJson("/api/admin/subjects/{$subject->id}/assign-teacher", [
            'teacher_id' => $teachers[0]->id,
        ])->assertOk();

        $this->postJson("/api/admin/subjects/{$subject->id}/assign-teacher", [
            'teacher_id' => $teachers[1]->id,
        ])->assertUnprocessable()
            ->assertJsonPath('status', false);

        $this->assertSame(1, $subject->teachers()->count());
        $this->assertDatabaseHas('subject_teacher', [
            'subject_id' => $subject->id,
            'teacher_id' => $teachers[0]->id,
        ]);
    }

    public function test_human_readable_attributes_are_title_cased_without_changing_stored_values(): void
    {
        $subject = Subject::create([
            'code' => 'SCI1',
            'name' => 'science and technology',
            'grade_level' => 'Grade 1',
            'units' => 1,
            'status' => 'Active',
        ]);

        $this->assertSame('Science And Technology', $subject->name);
        $this->assertSame('science and technology', $subject->getRawOriginal('name'));
        $this->assertSame('Science And Technology', $subject->toArray()['name']);
        $this->assertSame('MAPEH And AP', \App\Support\TitleCase::format('mapeh and ap'));

        $student = Student::make([
            'first_name' => 'john',
            'middle_name' => 'DOE',
            'last_name' => 'smith',
            'grade' => 'grade four',
        ]);
        $activity = Activity::make([
            'title' => 'science and technology',
            'description' => 'science and technology',
        ]);
        $lesson = Lesson::make([
            'title' => 'MATH lesson four',
            'description' => 'science and technology',
        ]);
        $question = QuizQuestion::make([
            'question' => 'what is photosynthesis',
            'choices' => ['green plant', 'soil'],
            'correct_answer' => 'green plant',
        ]);

        $this->assertSame('John', $student->first_name);
        $this->assertSame('Doe', $student->middle_name);
        $this->assertSame('Smith', $student->last_name);
        $this->assertSame('Grade Four', $student->grade);
        $this->assertSame('Science And Technology', $activity->title);
        $this->assertSame('Science And Technology', $activity->description);
        $this->assertSame('science and technology', $activity->getAttributes()['description']);
        $this->assertSame('Math Lesson Four', $lesson->title);
        $this->assertSame('Science And Technology', $lesson->description);
        $this->assertSame('What Is Photosynthesis', $question->question);
        $this->assertSame(['green plant', 'soil'], $question->choices);
        $this->assertSame('green plant', $question->correct_answer);
    }
}
