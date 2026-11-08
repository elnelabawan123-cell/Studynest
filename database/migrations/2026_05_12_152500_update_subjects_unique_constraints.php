<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Subjects table — switch from "code is globally unique"
 * to the new spec rules:
 *
 *   ❌ Same NAME + same GRADE_LEVEL → blocked (DB-level)
 *   ❌ Same CODE + same GRADE_LEVEL → blocked (DB-level)
 *   ✅ Same NAME or CODE across different grades → allowed
 *
 * This lets "AP / Araling Panlipunan / Grade 1" coexist with
 * "AP / Araling Panlipunan / Grade 2".
 */
return new class extends Migration {
    public function up(): void
    {
        // Clear duplicates that would otherwise block the new composite indexes.
        // (Safe to run repeatedly — no-op when there are no dupes.)
        $this->dedupe('name');
        $this->dedupe('code');

        Schema::table('subjects', function (Blueprint $table) {
            // Drop the old global-unique on `code`. Use the explicit index name
            // so this works without doctrine/dbal.
            try {
                $table->dropUnique('subjects_code_unique');
            } catch (\Throwable $e) {
                // Index already gone — fine.
            }

            // New composite constraints (the spec rules, enforced at the DB).
            $table->unique(['name', 'grade_level'], 'subjects_name_grade_unique');
            $table->unique(['code', 'grade_level'], 'subjects_code_grade_unique');
        });
    }

    public function down(): void
    {
        Schema::table('subjects', function (Blueprint $table) {
            try { $table->dropUnique('subjects_name_grade_unique'); } catch (\Throwable $e) {}
            try { $table->dropUnique('subjects_code_grade_unique'); } catch (\Throwable $e) {}

            // Restore the old global-unique on `code`. May fail if duplicates
            // were introduced after up() ran — clean them up first if so.
            $table->unique('code', 'subjects_code_unique');
        });
    }

    /**
     * Keep the lowest-id row for each (column, grade_level) pair, delete the rest.
     * Prevents the new composite unique index from failing on existing dupes.
     */
    private function dedupe(string $column): void
    {
        $dupes = DB::table('subjects')
            ->select($column, 'grade_level', DB::raw('MIN(id) as keep_id'), DB::raw('COUNT(*) as c'))
            ->groupBy($column, 'grade_level')
            ->having('c', '>', 1)
            ->get();

        foreach ($dupes as $d) {
            DB::table('subjects')
                ->where($column, $d->{$column})
                ->where('grade_level', $d->grade_level)
                ->where('id', '!=', $d->keep_id)
                ->delete();
        }
    }
};
