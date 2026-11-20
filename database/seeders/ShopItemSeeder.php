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
                'name' => 'Show Answer (3s)',
                'description' => 'Ipakita ang tamang sagot sa loob ng 3 segundo habang nagku-quiz.',
                'emoji' => '💡',
                'price' => 30,
                'category' => 'booster',
                'is_active' => true,
                'effect_config' => ['type' => 'hint', 'reveal_seconds' => 3],
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
                'is_active' => true,
                'effect_config' => ['type' => 'cosmetic'],
            ],
            // ── Avatar Borders ───────────────────────────────────
            [
                'slug' => 'gold_crown_border',
                'name' => 'Gold Crown Border',
                'description' => 'Makintab na gintong frame para sa iyong profile avatar.',
                'emoji' => '👑',
                'price' => 25,
                'category' => 'border',
                'is_active' => true,
                'effect_config' => [
                    'type' => 'border',
                    'colors' => ['#FBBF24', '#F59E0B', '#FEF08A'],
                    'glow' => '#FBBF24',
                    'width' => 3.5,
                ],
            ],
            [
                'slug' => 'royal_violet_border',
                'name' => 'Royal Violet Glow',
                'description' => 'Mahiwagang lilang border na kumikinang sa iyong profile.',
                'emoji' => '🔮',
                'price' => 25,
                'category' => 'border',
                'is_active' => true,
                'effect_config' => [
                    'type' => 'border',
                    'colors' => ['#A855F7', '#6366F1', '#EC4899'],
                    'glow' => '#A855F7',
                    'width' => 3.5,
                ],
            ],
            [
                'slug' => 'ocean_wave_border',
                'name' => 'Ocean Wave Frame',
                'description' => 'Malamig at preskong asul na alon sa paligid ng avatar.',
                'emoji' => '🌊',
                'price' => 20,
                'category' => 'border',
                'is_active' => true,
                'effect_config' => [
                    'type' => 'border',
                    'colors' => ['#06B6D4', '#3B82F6', '#67E8F9'],
                    'glow' => '#38BDF8',
                    'width' => 3.0,
                ],
            ],
            [
                'slug' => 'emerald_leaf_border',
                'name' => 'Emerald Spark Frame',
                'description' => 'Matingkad na berdeng border para sa masiglang estudyante.',
                'emoji' => '🍀',
                'price' => 20,
                'category' => 'border',
                'is_active' => true,
                'effect_config' => [
                    'type' => 'border',
                    'colors' => ['#10B981', '#34D399', '#A7F3D0'],
                    'glow' => '#10B981',
                    'width' => 3.0,
                ],
            ],
            [
                'slug' => 'ruby_fire_border',
                'name' => 'Blazing Fire Frame',
                'description' => 'Nagbabagang apoy na frame para sa matapang na learner.',
                'emoji' => '🔥',
                'price' => 30,
                'category' => 'border',
                'is_active' => true,
                'effect_config' => [
                    'type' => 'border',
                    'colors' => ['#EF4444', '#F97316', '#FACC15'],
                    'glow' => '#F97316',
                    'width' => 3.5,
                ],
            ],
            [
                'slug' => 'rainbow_star_border',
                'name' => 'Rainbow Star Rim',
                'description' => 'Makulay na bahaghari na border na may bituin.',
                'emoji' => '🌈',
                'price' => 40,
                'category' => 'border',
                'is_active' => true,
                'effect_config' => [
                    'type' => 'border',
                    'colors' => ['#EF4444', '#F59E0B', '#10B981', '#3B82F6', '#A855F7'],
                    'glow' => '#8B5CF6',
                    'width' => 4.0,
                ],
            ],
        ];

        foreach ($items as $item) {
            ShopItem::updateOrCreate(['slug' => $item['slug']], $item);
        }

        // Hide discontinued tools and non-avatar fun items without deleting rows
        // so existing student_inventory and star_transactions records stay intact.
        ShopItem::whereIn('slug', [
            'double_stars',
            'skip_question',
            'fast_learner',
            'star_backpack',
            'cool_cap',
            'mystery_box',
        ])->update(['is_active' => false]);

        // Ensure remaining shop tools reflect the updated 3-second answer reveal
        // and Add Time naming cleanly even if legacy rows were previously seeded.
        ShopItem::where('slug', 'quiz_hint')->update([
            'name' => 'Show Answer (3s)',
            'description' => 'Ipakita ang tamang sagot sa loob ng 3 segundo habang nagku-quiz.',
            'price' => 30,
            'is_active' => true,
            'effect_config' => ['type' => 'hint', 'reveal_seconds' => 3],
        ]);
        ShopItem::where('slug', 'extra_time')->update([
            'name' => 'Add Time',
            'description' => 'Magdagdag ng 1 minuto sa oras ng iyong quiz.',
            'price' => 30,
            'is_active' => true,
            'effect_config' => ['type' => 'extra_time', 'seconds' => 60],
        ]);
    }
}
