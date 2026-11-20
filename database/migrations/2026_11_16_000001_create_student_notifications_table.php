<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_user_id')
                ->constrained('users')
                ->cascadeOnDelete();
            $table->string('notification_key', 120);
            $table->string('type', 32); // lesson | quiz | assignment | activity | reminder | reward | system
            $table->string('title');
            $table->text('body');
            $table->string('subject_name')->nullable();
            $table->string('target_type', 32)->nullable(); // lesson | activity
            $table->unsignedBigInteger('target_id')->nullable();
            $table->timestamp('event_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('dismissed_at')->nullable();
            $table->timestamps();

            $table->unique(['student_user_id', 'notification_key'], 'student_notif_user_key_unique');
            $table->index(['student_user_id', 'dismissed_at', 'event_at'], 'student_notif_active_idx');
            $table->index(['target_type', 'target_id'], 'student_notif_target_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_notifications');
    }
};
