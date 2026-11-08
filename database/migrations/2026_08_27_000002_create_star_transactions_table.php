<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only ledger — the single source of truth backing both
        // Reward History and the Star Shop purchase log. Every earn/spend
        // is one row; `balance_after` is a denormalized snapshot so
        // history views don't need to replay the whole ledger.
        Schema::create('star_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->integer('amount'); // positive = earn, negative = spend
            $table->unsignedInteger('balance_after');
            $table->string('type'); // game_reward | purchase | refund | adjustment
            $table->string('reference')->nullable(); // e.g. game_key or shop item slug
            $table->string('description');
            $table->timestamps();

            $table->index(['student_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('star_transactions');
    }
};
