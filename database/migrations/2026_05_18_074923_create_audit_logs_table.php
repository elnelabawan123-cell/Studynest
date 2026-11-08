<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('user_role')->nullable();
            $table->string('action');                   // e.g. "updated", "created", "deleted"
            $table->string('table_name');               // e.g. "users", "admin_settings"
            $table->unsignedBigInteger('record_id')->nullable();
            $table->json('old_value')->nullable();      // snapshot before change
            $table->json('new_value')->nullable();      // snapshot after change
            $table->text('description');                // Human-readable summary
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('set null');
            $table->index(['user_id', 'created_at']);
            $table->index(['table_name', 'record_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};