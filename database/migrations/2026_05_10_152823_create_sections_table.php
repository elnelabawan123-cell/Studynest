<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sections', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('grade_level', 50);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // Prevent duplicate section name within same grade
            $table->unique(['name', 'grade_level']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sections');
    }
};