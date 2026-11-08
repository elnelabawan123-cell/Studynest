<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $snapshotPath = database_path('seeders/local_snapshot.json');
        if (is_file($snapshotPath)) {
            $snapshot = json_decode((string) file_get_contents($snapshotPath), true);
            if (is_array($snapshot)) {
                Schema::disableForeignKeyConstraints();
                try {
                    foreach ($snapshot as $table => $rows) {
                        if (!is_array($rows) || empty($rows) || !Schema::hasTable($table)) {
                            continue;
                        }
                        foreach (array_chunk($rows, 100) as $chunk) {
                            DB::table($table)->insertOrIgnore($chunk);
                        }
                    }
                } finally {
                    Schema::enableForeignKeyConstraints();
                }
            }
        }

        $this->call(SectionSeeder::class);
        $this->call(ShopItemSeeder::class);
        if (DB::table('school_years')->count() === 0) {
            $this->call(SchoolYearSeeder::class);
        }
    }
}
