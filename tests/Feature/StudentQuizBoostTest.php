<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\QuizQuestion;
use App\Models\Section;
use App\Models\ShopItem;
use App\Models\Student;
use App\Models\StudentInventory;
use App\Models\Subject;
use App\Models\User;
use Database\Seeders\ShopItemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentQuizBoostTest extends TestCase
{
    use RefreshDatabase;

    public function test_quiz_boosters_cost_30_stars_and_apply_the_configured_effects(): void
    {
        (new ShopItemSeeder())->run();
        $this->assertSame(30, ShopItem::where('slug', 'quiz_hint')->value('price'));
        $this->assertSame(30, ShopItem::where('slug', 'extra_time')->value('price'));
        $this->assertSame(60, ShopItem::where('slug', 'extra_time')->firstOrFail()->effect_config['seconds']);

        [$user, $student, $activity] = $this->createStudentAndQuiz();
        $activity->subject->students()->attach($student->id);
        $question = QuizQuestion::create([
            'activity_id' => $activity->id,
            'order' => 1,
            'question' => 'What is 2 + 2?',
            'answer_type' => 'Multiple Choice',
            'choices' => ['3', '4', '5'],
            'correct_answer' => '4',
            'points' => 10,
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/student/activities/{$activity->id}/start")
            ->assertOk();

        $this->postJson('/api/student/shop/purchase', ['item_slug' => 'quiz_hint'])
            ->assertOk()
            ->assertJsonPath('data.balance', 60);
        $this->postJson('/api/student/shop/purchase', ['item_slug' => 'extra_time'])
            ->assertOk()
            ->assertJsonPath('data.balance', 30);

        $this->postJson('/api/student/inventory/consume', [
            'item_slug' => 'quiz_hint',
            'activity_id' => $activity->id,
            'quiz_question_id' => $question->id,
            'context_label' => 'Quiz: Math',
        ])->assertOk()
            ->assertJsonPath('data.effect_config.correct_answer', '4')
            ->assertJsonPath('data.effect_config.reveal_seconds', 3)
            ->assertJsonPath('data.remaining_quantity', 0);

        $this->postJson('/api/student/inventory/consume', [
            'item_slug' => 'extra_time',
            'activity_id' => $activity->id,
            'context_label' => 'Quiz: Math',
        ])->assertOk()
            ->assertJsonPath('data.effect_config.seconds', 60)
            ->assertJsonPath('data.remaining_quantity', 0);

        $this->assertSame(30, $student->fresh()->stars_balance);
        $this->assertDatabaseHas('star_transactions', [
            'student_id' => $student->id,
            'amount' => -30,
            'type' => 'purchase',
        ]);
        $this->assertDatabaseCount('student_inventory', 2);
    }

    public function test_answer_reveal_cannot_be_used_for_another_quiz_or_without_an_active_attempt(): void
    {
        (new ShopItemSeeder())->run();
        [$user, $student, $activity] = $this->createStudentAndQuiz();
        $activity->subject->students()->attach($student->id);
        $question = QuizQuestion::create([
            'activity_id' => $activity->id,
            'order' => 1,
            'question' => 'What is 2 + 2?',
            'answer_type' => 'Multiple Choice',
            'choices' => ['3', '4'],
            'correct_answer' => '4',
            'points' => 10,
        ]);
        $item = ShopItem::where('slug', 'quiz_hint')->firstOrFail();
        StudentInventory::create([
            'student_id' => $student->id,
            'shop_item_id' => $item->id,
            'quantity' => 1,
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/student/inventory/consume', [
                'item_slug' => 'quiz_hint',
                'activity_id' => $activity->id,
                'quiz_question_id' => $question->id,
            ])
            ->assertUnprocessable();
        $this->assertSame(1, $student->fresh()->inventory()->where('shop_item_id', $item->id)->value('quantity'));

        $this->postJson("/api/student/activities/{$activity->id}/start")->assertOk();
        $anotherActivity = $this->createActivity();
        $anotherActivity->subject->students()->attach($student->id);

        $this->postJson('/api/student/inventory/consume', [
            'item_slug' => 'quiz_hint',
            'activity_id' => $anotherActivity->id,
            'quiz_question_id' => $question->id,
        ])->assertUnprocessable();
        $this->assertSame(1, $student->fresh()->inventory()->where('shop_item_id', $item->id)->value('quantity'));
    }

    public function test_shop_catalog_exposes_only_allowed_tools_avatars_and_borders(): void
    {
        (new ShopItemSeeder())->run();
        [$user] = $this->createStudentAndQuiz();

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/student/shop/items')
            ->assertOk();

        $items = collect($response->json('data'));
        $boosterSlugs = $items->where('category', 'booster')->pluck('slug')->sort()->values()->all();
        $this->assertSame(['extra_time', 'quiz_hint'], $boosterSlugs);
        $this->assertSame(3, $items->firstWhere('slug', 'quiz_hint')['effect_config']['reveal_seconds']);

        foreach (['double_stars', 'skip_question', 'fast_learner', 'star_backpack', 'cool_cap', 'mystery_box'] as $removedSlug) {
            $this->assertNull($items->firstWhere('slug', $removedSlug));
        }

        $this->assertGreaterThan(0, $items->where('category', 'character')->count());
        $this->assertGreaterThan(0, $items->where('category', 'border')->count());
        $this->assertSame(0, $items->where('category', 'funItem')->count());
    }

    public function test_student_can_purchase_and_equip_avatar_border_and_receive_it_across_endpoints(): void
    {
        (new ShopItemSeeder())->run();
        [$user, $student] = $this->createStudentAndQuiz();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/student/shop/equip-border', ['item_slug' => 'gold_crown_border'])
            ->assertForbidden();

        $this->postJson('/api/student/shop/purchase', ['item_slug' => 'quiz_hint'])->assertOk();
        $this->postJson('/api/student/shop/equip-border', ['item_slug' => 'quiz_hint'])
            ->assertUnprocessable();

        $this->postJson('/api/student/shop/purchase', ['item_slug' => 'gold_crown_border'])->assertOk();
        $this->postJson('/api/student/shop/equip-border', ['item_slug' => 'gold_crown_border'])
            ->assertOk()
            ->assertJsonPath('data.equipped_border_slug', 'gold_crown_border')
            ->assertJsonPath('data.equipped_border_config.type', 'border');

        $this->assertSame('gold_crown_border', $student->fresh()->equipped_border_slug);

        $this->getJson('/api/student/wallet')
            ->assertOk()
            ->assertJsonPath('data.equipped_border_slug', 'gold_crown_border')
            ->assertJsonPath('data.equipped_border_config.type', 'border');

        $this->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.equipped_border_slug', 'gold_crown_border')
            ->assertJsonPath('data.equipped_border_config.type', 'border');

        $this->postJson('/api/student/shop/equip-border', ['item_slug' => null])
            ->assertOk()
            ->assertJsonPath('data.equipped_border_slug', null);
    }

    private function createStudentAndQuiz(): array
    {
        $user = User::create([
            'name' => 'Quiz Student',
            'email' => 'quiz-student@example.test',
            'username' => 'quiz-student',
            'password' => 'student-password',
            'role' => 'student',
        ]);
        $section = Section::create([
            'name' => 'Section A',
            'grade_level' => 'Grade 3',
            'is_active' => true,
        ]);
        $student = Student::create([
            'user_id' => $user->id,
            'student_number' => 'STU-2026-9001',
            'first_name' => 'Quiz',
            'last_name' => 'Student',
            'email' => $user->email,
            'grade' => 'Grade 3',
            'section_id' => $section->id,
            'gpa' => 0,
            'status' => 'Active',
            'stars_balance' => 90,
        ]);

        return [$user, $student, $this->createActivity()];
    }

    private function createActivity(): Activity
    {
        $sequence = User::where('role', 'teacher')->count() + 1;
        $teacher = User::create([
            'name' => 'Quiz Teacher',
            'email' => "quiz-teacher-{$sequence}@example.test",
            'username' => "quiz-teacher-{$sequence}",
            'password' => 'teacher-password',
            'role' => 'teacher',
        ]);
        $subject = Subject::create([
            'code' => "MATH-{$sequence}",
            'name' => "Mathematics {$sequence}",
            'grade_level' => 'Grade 3',
            'units' => 1,
            'status' => 'Active',
        ]);
        $today = now()->toDateString();

        return Activity::create([
            'teacher_id' => $teacher->id,
            'subject_id' => $subject->id,
            'type' => 'Quiz',
            'title' => 'Math Quiz',
            'status' => 'Open',
            'open_date' => $today,
            'open_time' => '00:00:00',
            'due_date' => $today,
            'due_time' => '23:59:00',
            'total_points' => 10,
            'time_limit' => 5,
        ]);
    }
}
