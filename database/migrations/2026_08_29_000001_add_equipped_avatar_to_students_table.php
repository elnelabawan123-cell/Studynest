<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            // Plain slug string, not an FK id — matches the existing
            // convention where star_transactions.reference already stores
            // shop_items.slug as free text. Null = no avatar equipped yet
            // (profile falls back to name initials).
            $table->string('equipped_avatar_slug')->nullable()->after('stars_balance');
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropColumn('equipped_avatar_slug');
        });
    }
};
