<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('shop_items')->where('slug', 'quiz_hint')->update([
            'name' => 'Correct Answer Reveal',
            'description' => 'Ipakita ang tamang sagot sa loob ng 5 segundo habang nagku-quiz.',
            'price' => 30,
            'effect_config' => json_encode(['type' => 'hint', 'reveal_seconds' => 5]),
        ]);

        DB::table('shop_items')->where('slug', 'extra_time')->update([
            'name' => 'Extra Time',
            'description' => 'Magdagdag ng 1 minuto sa oras ng iyong quiz.',
            'price' => 30,
            'effect_config' => json_encode(['type' => 'extra_time', 'seconds' => 60]),
        ]);
    }

    public function down(): void
    {
        DB::table('shop_items')->where('slug', 'quiz_hint')->update([
            'name' => 'Quiz Hint',
            'description' => 'Makakuha ng kapaki-pakinabang na clue sa kasalukuyang tanong.',
            'price' => 20,
            'effect_config' => json_encode(['type' => 'hint']),
        ]);

        DB::table('shop_items')->where('slug', 'extra_time')->update([
            'description' => 'Dagdag na 2 minuto sa oras ng iyong quiz.',
            'price' => 25,
            'effect_config' => json_encode(['type' => 'extra_time', 'seconds' => 120]),
        ]);
    }
};
