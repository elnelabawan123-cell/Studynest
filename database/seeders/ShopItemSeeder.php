<?php

namespace Database\Seeders;

use App\Models\ShopItem;
use Illuminate\Database\Seeder;

class ShopItemSeeder extends Seeder
{
    public function run(): void
    {
        $items = [
            // ── Functional boosters — these are the items the Quiz screen
            // actually consumes (see StudentInventoryController::consume).
            [
                'slug' => 'quiz_hint',
                'name' => 'Correct Answer Reveal',
                'description' => 'Ipakita ang tamang sagot sa loob ng 5 segundo habang nagku-quiz.',
                'emoji' => '💡',
                'price' => 30,
                'category' => 'booster',
                'effect_config' => ['type' => 'hint', 'reveal_seconds' => 5],
            ],
            [
                'slug' => 'extra_time',
                'name' => 'Extra Time',
                'description' => 'Magdagdag ng 1 minuto sa oras ng iyong quiz.',
                'emoji' => '⏱️',
                'price' => 30,
                'category' => 'booster',
                'effect_config' => ['type' => 'extra_time', 'seconds' => 60],
            ],
            [
                'slug' => 'double_stars',
                'name' => 'Double Stars (1 Game)',
                'description' => 'Doble ang stars na makukuha mo sa susunod na laro.',
                'emoji' => '⭐',
                'price' => 10,
                'category' => 'booster',
                'effect_config' => ['type' => 'double_stars'],
            ],
            [
                'slug' => 'skip_question',
                'name' => 'Skip Question',
                'description' => 'Laktawan ang isang mahirap na tanong nang walang parusa.',
                'emoji' => '⏭️',
                'price' => 12,
                'category' => 'booster',
                'effect_config' => ['type' => 'skip_question'],
            ],
            [
                'slug' => 'fast_learner',
                'name' => 'Fast Learner',
                'description' => 'Bawasan ang oras na kailangan para makumpleto ang quiz.',
                'emoji' => '🚀',
                'price' => 18,
                'category' => 'booster',
                'effect_config' => ['type' => 'fast_learner'],
            ],
            // ── Cosmetic items — real persisted purchases/inventory, no
            // in-app mechanical effect.
            [
                'slug' => 'star_backpack',
                'name' => 'Star Backpack',
                'description' => 'Isang makinang na backpack accessory para sa avatar mo.',
                'emoji' => '🎒',
                'price' => 15,
                'category' => 'funItem',
                'effect_config' => ['type' => 'cosmetic'],
            ],
            [
                'slug' => 'cool_cap',
                'name' => 'Cool Cap',
                'description' => 'Istilong takip para sa iyong avatar.',
                'emoji' => '🧢',
                'price' => 15,
                'category' => 'funItem',
                'effect_config' => ['type' => 'cosmetic'],
            ],
            [
                'slug' => 'mystery_box',
                'name' => 'Mystery Box',
                'description' => 'Random na surprise item — subukan ang iyong swerte!',
                'emoji' => '🎁',
                'price' => 20,
                'category' => 'funItem',
                'effect_config' => ['type' => 'cosmetic'],
            ],
            // ── Avatars — equippable as the student's profile picture via
            // StudentShopController::equipAvatar(). Buying one just adds it
            // to inventory like anything else; equipping is a separate,
            // free action (see equip-avatar route) that never touches stars
            // or quantity.
            [
                'slug' => 'astronaut',
                'name' => 'Astronaut',
                'description' => 'Isang cool na astronaut avatar para sa iyong profile.',
                'emoji' => '🧑‍🚀',
                'price' => 25,
                'category' => 'character',
                'effect_config' => ['type' => 'cosmetic'],
            ],
            [
                'slug' => 'green_dino',
                'name' => 'Green Dino',
                'description' => 'Isang friendly na dinosaur avatar.',
                'emoji' => '🦕',
                'price' => 30,
                'category' => 'character',
                'effect_config' => ['type' => 'cosmetic'],
            ],
            [
                'slug' => 'brave_lion',
                'name' => 'Brave Lion',
                'description' => 'Isang matapang na leon avatar.',
                'emoji' => '🦁',
                'price' => 25,
                'category' => 'character',
                'effect_config' => ['type' => 'cosmetic'],
            ],
            [
                'slug' => 'cuddly_panda',
                'name' => 'Cuddly Panda',
                'description' => 'Isang malambing na panda avatar.',
                'emoji' => '🐼',
                'price' => 25,
                'category' => 'character',
                'effect_config' => ['type' => 'cosmetic'],
            ],
            [
                'slug' => 'magic_unicorn',
                'name' => 'Magic Unicorn',
                'description' => 'Isang mahiwagang unicorn avatar.',
                'emoji' => '🦄',
                'price' => 35,
                'category' => 'character',
                'effect_config' => ['type' => 'cosmetic'],
            ],
            [
                'slug' => 'friendly_robot',
                'name' => 'Friendly Robot',
                'description' => 'Isang matalino at magiliw na robot avatar.',
                'emoji' => '🤖',
                'price' => 30,
                'category' => 'character',
                'effect_config' => ['type' => 'cosmetic'],
            ],
            [
                'slug' => 'clever_fox',
                'name' => 'Clever Fox',
                'description' => 'Isang tusong fox avatar.',
                'emoji' => '🦊',
                'price' => 25,
                'category' => 'character',
                'effect_config' => ['type' => 'cosmetic'],
            ],
            [
                'slug' => 'mighty_dragon',
                'name' => 'Mighty Dragon',
                'description' => 'Isang makapangyarihang dragon avatar.',
                'emoji' => '🐉',
                'price' => 35,
                'category' => 'character',
                'effect_config' => ['type' => 'cosmetic'],
            ],
            [
                'slug' => 'cool_penguin',
                'name' => 'Cool Penguin',
                'description' => 'Isang malamig-sa-ulo na penguin avatar.',
                'emoji' => '🐧',
                'price' => 20,
                'category' => 'character',
                'effect_config' => ['type' => 'cosmetic'],
            ],
            [
                'slug' => 'playful_monkey',
                'name' => 'Playful Monkey',
                'description' => 'Isang masayang unggoy avatar.',
                'emoji' => '🐵',
                'price' => 20,
                'category' => 'character',
                'effect_config' => ['type' => 'cosmetic'],
            ],
            [
                'slug' => 'happy_puppy',
                'name' => 'Happy Puppy',
                'description' => 'Isang masayahing tuta avatar.',
                'emoji' => '🐶',
                'price' => 20,
                'category' => 'character',
                'effect_config' => ['type' => 'cosmetic'],
            ],
        ];

        foreach ($items as $item) {
            ShopItem::updateOrCreate(['slug' => $item['slug']], $item);
        }
    }
}
