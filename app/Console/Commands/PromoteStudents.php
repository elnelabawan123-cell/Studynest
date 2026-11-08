<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\SchoolyearController;
use App\Models\SchoolYear;
use Illuminate\Console\Command;

class PromoteStudents extends Command
{
    protected $signature   = 'students:promote';
    protected $description = 'Auto-promote students when the school year end date is reached, then create next school year';

    public function handle(): int
    {
        $sy = SchoolYear::active();

        if (! $sy) {
            $this->warn('No active school year found.');
            return self::SUCCESS;
        }

        if ($sy->promoted) {
            $this->info("Students already promoted for {$sy->label}. Skipping.");
            return self::SUCCESS;
        }

        if (! $sy->isEndDateReached()) {
            $daysLeft = now()->startOfDay()->diffInDays($sy->end_date->startOfDay(), false);
            $this->info("School year {$sy->label} ends in {$daysLeft} day(s). No promotion yet.");
            return self::SUCCESS;
        }

        $this->info("End date reached for {$sy->label}. Running promotion…");

        [$promoted, $graduated, $skipped, $nextSy] = SchoolyearController::runPromotion($sy);

        $this->info("✅ Done — {$promoted} promoted, {$graduated} graduated, {$skipped} skipped.");

        if ($nextSy) {
            $this->info("📅 New school year created: {$nextSy->label}");
            $this->info("   Start: {$nextSy->start_date->format('M d, Y')}");
            $this->info("   End:   {$nextSy->end_date->format('M d, Y')} (editable by admin)");
        }

        return self::SUCCESS;
    }
}