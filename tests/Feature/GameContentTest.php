<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\GameContentController;
use App\Models\Section;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

class GameContentTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_pantig_tower_content_has_valid_filipino_syllables(): void
    {
        $default = GameContentController::defaultContent('g3m:0:6');
        $words = $default['words'] ?? [];
        $this->assertGreaterThanOrEqual(10, count($words));

        $expectedSampleWords = ['AMA', 'PAMILYA', 'BATA', 'MAGULANG', 'PAGMAMAHAL', 'BAHAY', 'KAIBIGAN', 'MASAYA', 'LARUAN', 'TAHANAN'];
        $found = array_column($words, 'word');
        foreach ($expectedSampleWords as $sample) {
            $this->assertContains($sample, $found, "Missing sample word {$sample}");
        }

        foreach ($words as $entry) {
            $joined = implode('', $entry['syllables']);
            $this->assertSame($entry['word'], $joined);
            $this->assertSame(count($entry['syllables']), $entry['syllableCount']);
            $this->assertSame($entry['syllableCount'], $entry['correctAnswer']);
            $this->assertContains($entry['difficulty'], ['easy', 'medium', 'advanced']);
        }
    }

    public function test_teacher_and_admin_can_update_game_content_and_student_receives_updates(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);

        $teacherUser = User::create([
            'name' => 'Guro Filipino',
            'username' => 'guro_filipino',
            'email' => 'guro.filipino@studynest.test',
            'password' => bcrypt('password'),
            'role' => 'teacher',
            'is_active' => true,
        ]);

        $studentUser = User::create([
            'name' => 'Mag-aaral 3',
            'username' => 'mag_aaral_3',
            'email' => 'student3@studynest.test',
            'password' => bcrypt('password'),
            'role' => 'student',
            'is_active' => true,
        ]);

        $section = Section::firstOrCreate(
            ['name' => 'Section A', 'grade_level' => 'Grade 3'],
            ['is_active' => true]
        );

        Student::create([
            'user_id' => $studentUser->id,
            'student_number' => 'SN-3007',
            'first_name' => 'Juan',
            'last_name' => 'Santos',
            'email' => $studentUser->email,
            'grade' => 'Grade 3',
            'section_id' => $section->id,
            'gpa' => 0,
            'status' => 'Active',
            'stars_balance' => 0,
        ]);

        // Student sees default content before override
        $this->actingAs($studentUser, 'sanctum')
            ->getJson('/api/student/game-content/g3m:0:6')
            ->assertOk()
            ->assertJsonPath('data.source', 'default')
            ->assertJsonPath('data.content.gameTitle', 'Pantig Tower');

        // Student cannot modify content
        $this->actingAs($studentUser, 'sanctum')
            ->putJson('/api/game-content/g3m:0:6', [
                'words' => [[
                    'word' => 'AMA',
                    'syllables' => ['A', 'MA'],
                    'distractors' => ['BA', 'TO'],
                    'difficulty' => 'easy',
                ]],
            ])
            ->assertStatus(403);

        // Teacher rejects malformed syllable division
        $this->actingAs($teacherUser, 'sanctum')
            ->putJson('/api/game-content/g3m:0:6', [
                'words' => [[
                    'word' => 'PAMILYA',
                    'syllables' => ['PA', 'MI', 'TO'],
                    'distractors' => ['BA'],
                    'difficulty' => 'medium',
                ]],
            ])
            ->assertStatus(422);

        // Teacher saves valid updated words
        $this->actingAs($teacherUser, 'sanctum')
            ->putJson('/api/game-content/g3m:0:6', [
                'gameTitle' => 'Pantig Tower',
                'words' => [
                    [
                        'word' => 'KAPATID',
                        'syllables' => ['KA', 'PA', 'TID'],
                        'distractors' => ['BA', 'TO', 'MU'],
                        'difficulty' => 'medium',
                        'round' => 2,
                        'points' => 30,
                        'bonusPoints' => 20,
                    ],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.source', 'database');

        // Student immediately fetches updated backend words without rebuilding Unity
        $this->actingAs($studentUser, 'sanctum')
            ->getJson('/api/student/game-content/g3m:0:6')
            ->assertOk()
            ->assertJsonPath('data.source', 'database')
            ->assertJsonPath('data.content.words.0.word', 'KAPATID')
            ->assertJsonPath('data.content.words.0.syllableCount', 3);
    }
}
