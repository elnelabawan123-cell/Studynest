<?php

namespace Tests\Feature;

use App\Models\Section;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

class StudentGameProgressTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    public function test_partial_round_progress_persists_per_student_without_faking_completion_or_leaking_between_students(): void
    {
        [$userA, $studentA] = $this->createStudent('studentA', 'STU-2026-1001');
        [$userB, $studentB] = $this->createStudent('studentB', 'STU-2026-1002');

        // Student A completes Round 1 of g3m:0:0 (Grade 3 Pangngalan Catcher, 3 rounds) and leaves.
        $this->actingAs($userA, 'sanctum')
            ->postJson('/api/student/game-progress', [
                'game_key' => 'g3m:0:0',
                'title' => 'Aralin 1: Pangngalan Catcher',
                'grade' => 3,
                'yunit' => 0,
                'aralin' => 0,
                'has_rounds' => true,
                'completed_rounds' => 1,
                'current_round' => 2,
                'total_rounds' => 3,
                'round_scores' => [40],
                'total_score' => 40,
                'earned_stars' => 1,
                'correct_answers' => 4,
                'incorrect_answers' => 1,
                'game_completed' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'in_progress')
            ->assertJsonPath('data.stars', 0)
            ->assertJsonPath('data.progress_data.student_id', 'STU-2026-1001')
            ->assertJsonPath('data.progress_data.completed_rounds', 1)
            ->assertJsonPath('data.progress_data.current_round', 2)
            ->assertJsonPath('data.progress_data.earned_stars', 1)
            ->assertJsonPath('data.progress_data.game_completed', false);

        // Partial in-progress row must NOT appear as a completed reward in /api/student/game-rewards.
        $this->actingAs($userA, 'sanctum')
            ->getJson('/api/student/game-rewards')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        // Student B has isolated progress (none for g3m:0:0).
        $this->actingAs($userB, 'sanctum')
            ->getJson('/api/student/game-progress')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        // Student A fetches their own progress -> sees Round 2 waiting.
        $this->actingAs($userA, 'sanctum')
            ->getJson('/api/student/game-progress')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.game_key', 'g3m:0:0')
            ->assertJsonPath('data.0.progress_data.completed_rounds', 1)
            ->assertJsonPath('data.0.progress_data.current_round', 2);

        // Completing g3m:0:0 saves monotonically and does NOT affect another aralin (g3m:0:1).
        $this->actingAs($userA, 'sanctum')
            ->postJson('/api/student/game-progress', [
                'game_key' => 'g3m:0:0',
                'title' => 'Aralin 1: Pangngalan Catcher',
                'grade' => 3,
                'yunit' => 0,
                'aralin' => 0,
                'has_rounds' => true,
                'completed_rounds' => 3,
                'current_round' => 3,
                'total_rounds' => 3,
                'round_scores' => [40, 50, 60],
                'total_score' => 150,
                'earned_stars' => 3,
                'correct_answers' => 14,
                'incorrect_answers' => 1,
                'game_completed' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.progress_data.game_completed', true);

        // Final reward store credits 3 stars.
        $this->actingAs($userA, 'sanctum')
            ->postJson('/api/student/game-rewards', [
                'game_key' => 'g3m:0:0',
                'title' => 'Aralin 1: Pangngalan Catcher',
                'subject' => 'Grade 3',
                'stars' => 3,
            ])
            ->assertOk()
            ->assertJsonPath('data.stars', 3);

        // Replaying with fewer rounds / stars must NOT erase completed status or best stars.
        $this->actingAs($userA, 'sanctum')
            ->postJson('/api/student/game-progress', [
                'game_key' => 'g3m:0:0',
                'title' => 'Aralin 1: Pangngalan Catcher',
                'grade' => 3,
                'yunit' => 0,
                'aralin' => 0,
                'has_rounds' => true,
                'completed_rounds' => 1,
                'current_round' => 2,
                'total_rounds' => 3,
                'round_scores' => [20],
                'total_score' => 20,
                'earned_stars' => 1,
                'correct_answers' => 2,
                'incorrect_answers' => 3,
                'game_completed' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.stars', 3)
            ->assertJsonPath('data.progress_data.completed_rounds', 3)
            ->assertJsonPath('data.progress_data.earned_stars', 3)
            ->assertJsonPath('data.progress_data.game_completed', true);
    }

    public function test_partial_round_in_grade1_abakada_does_not_unlock_next_letter_prerequisite(): void
    {
        [$user, $student] = $this->createStudent('g1student', 'STU-2026-2001');

        // Saving in-progress mechanics for g1:0:0 must not unlock g1:0:1.
        for ($i = 0; $i < 4; $i++) {
            $this->actingAs($user, 'sanctum')
                ->postJson('/api/student/game-progress', [
                    'game_key' => "g1:0:0:m{$i}",
                    'title' => "Titik A M{$i}",
                    'grade' => 1,
                    'yunit' => 0,
                    'aralin' => 0,
                    'has_rounds' => false,
                    'completed_rounds' => 1,
                    'current_round' => 1,
                    'total_rounds' => 1,
                    'round_scores' => [10],
                    'total_score' => 10,
                    'earned_stars' => 1,
                    'correct_answers' => 1,
                    'incorrect_answers' => 0,
                    'game_completed' => false,
                ])
                ->assertOk();
        }

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/student/game-rewards', [
                'game_key' => 'g1:0:1:m0',
                'title' => 'Titik E M0',
                'subject' => 'Grade 1',
                'stars' => 3,
            ])
            ->assertStatus(403);
    }

    private function createStudent(string $username, string $studentNumber): array
    {
        $user = User::create([
            'name' => ucfirst($username),
            'email' => "{$username}@example.test",
            'username' => $username,
            'password' => 'student-password',
            'role' => 'student',
        ]);
        $section = Section::firstOrCreate(
            ['name' => 'Section A', 'grade_level' => 'Grade 3'],
            ['is_active' => true]
        );
        $student = Student::create([
            'user_id' => $user->id,
            'student_number' => $studentNumber,
            'first_name' => ucfirst($username),
            'last_name' => 'Learner',
            'email' => $user->email,
            'grade' => 'Grade 3',
            'section_id' => $section->id,
            'gpa' => 0,
            'status' => 'Active',
            'stars_balance' => 0,
        ]);

        return [$user, $student];
    }
}
