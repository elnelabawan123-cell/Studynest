<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_contents', function (Blueprint $table) {
            $table->id();
            $table->string('game_key', 100)->unique();
            $table->unsignedTinyInteger('grade')->default(3);
            $table->unsignedTinyInteger('yunit')->default(0);
            $table->unsignedTinyInteger('aralin')->default(0);
            $table->string('title', 255);
            $table->json('content');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_contents');
    }
};

