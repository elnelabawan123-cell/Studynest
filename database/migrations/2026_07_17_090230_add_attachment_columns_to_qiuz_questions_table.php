<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds supporting-material attachments to quiz questions (image or PDF),
 * per the spec: "The teacher can attach supporting materials to each question".
 *
 * NOTE ON `choices`: this migration does NOT change the `choices` column.
 * It's assumed to already be a JSON/array-cast column on quiz_questions.
 * Going forward each choice should be stored as an object instead of a
 * plain string, so a choice can hold text, an image, or both:
 *
 *   ["choices"] = [
 *     { "text": "Manila",    "image": null },
 *     { "text": null,        "image": "https://.../volcano.jpg" },
 *     { "text": "Cebu City", "image": "https://.../cebu.jpg" },
 *   ]
 *
 * No schema change is needed for that — just a shape change in what the
 * app writes into the existing JSON column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quiz_questions', function (Blueprint $table) {
            $table->string('attachment_path')->nullable()->after('correct_answer');
            $table->enum('attachment_type', ['image', 'pdf'])->nullable()->after('attachment_path');
        });
    }

    public function down(): void
    {
        Schema::table('quiz_questions', function (Blueprint $table) {
            $table->dropColumn(['attachment_path', 'attachment_type']);
        });
    }
};