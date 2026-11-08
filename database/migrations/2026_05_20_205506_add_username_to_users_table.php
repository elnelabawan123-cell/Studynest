<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 100)->nullable()->unique()->after('email');
            $table->timestamp('archived_at')->nullable()->after('is_first_login');
        });

        // Backfill existing users with a temporary username
        \DB::table('users')->whereNull('username')->get()->each(function ($u) {
            $parts = explode(' ', trim($u->name));
            $last  = strtolower(preg_replace('/[^a-zA-Z]/', '', end($parts)));
            $base  = $last . '.SDA';
            $username = $base;
            $i = 1;
            while (\DB::table('users')->where('username', $username)->where('id', '!=', $u->id)->exists()) {
                $username = $base . $i++;
            }
            \DB::table('users')->where('id', $u->id)->update(['username' => $username]);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['username', 'archived_at']);
        });
    }
};