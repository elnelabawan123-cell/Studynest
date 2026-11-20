<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();
            $table->string('notification_key', 120);
            $table->string('type', 40);
            $table->string('title');
            $table->text('message');
            $table->string('target_type', 40)->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->timestamp('event_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('dismissed_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'notification_key'], 'staff_notif_user_key_unique');
            $table->index(['user_id', 'dismissed_at', 'event_at'], 'staff_notif_active_idx');
            $table->index(['target_type', 'target_id'], 'staff_notif_target_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_notifications');
    }
};
